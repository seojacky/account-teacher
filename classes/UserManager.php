<?php
// classes/UserManager.php

//require_once 'config/database.php';
//require_once 'classes/Auth.php';

require_once '../config/database.php';
require_once 'Auth.php';

class UserManager {
    private $db;
    private $auth;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auth = new Auth();
    }
    
    /**
     * Создание нового пользователя
     */
    public function createUser($userData, $currentUser) {
        // Проверяем права доступа - только админ может создавать пользователей
        if ($currentUser['role'] !== 'admin') {
            return ['error' => 'Недостатньо прав для створення користувачів', 'code' => 403];
        }
        
        // Валидация данных
        $validation = $this->validateUserData($userData);
        if (!$validation['valid']) {
            return ['error' => $validation['message'], 'code' => 400];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Проверяем уникальность employee_id и email
            if ($this->isEmployeeIdExists($userData['employee_id'])) {
                return ['error' => 'ID працівника вже існує', 'code' => 400];
            }
            
            if (!empty($userData['email']) && $this->isEmailExists($userData['email'])) {
                return ['error' => 'Email вже використовується', 'code' => 400];
            }
            
            // Генерируем пароль если не указан
            $password = !empty($userData['password']) ? $userData['password'] : $this->generatePassword();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Вставляем пользователя
            $stmt = $this->db->prepare("
                INSERT INTO users (employee_id, role_id, faculty_id, department_id, 
                                 full_name, email, password_hash, position, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userData['employee_id'],
                $userData['role_id'],
                $userData['faculty_id'] ?? null,
                $userData['department_id'] ?? null,
                $userData['full_name'],
                $userData['email'] ?? null,
                $passwordHash,
                $userData['position'] ?? null,
                isset($userData['is_active']) ? $userData['is_active'] : 1
            ]);
            
            $userId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            // Логируем создание пользователя
            $this->logActivity($currentUser['id'], 'create_user', 
                "Створено користувача: {$userData['full_name']} (ID: {$userData['employee_id']})");
            
            return [
                'success' => true,
                'user_id' => $userId,
                'password' => $password,
                'message' => 'Користувача успішно створено'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            writeErrorLog("Create user error: " . $e->getMessage());
            return ['error' => 'Помилка створення користувача', 'code' => 500];
        }
    }
    
    /**
     * Обновление данных пользователя
     */
    public function updateUser($userId, $userData, $currentUser) {
        // Проверяем права доступа
        if (!$this->canManageUser($currentUser, $userId)) {
            return ['error' => 'Недостатньо прав для редагування користувача', 'code' => 403];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Получаем текущие данные пользователя
            $currentData = $this->getUserById($userId);
            if (!$currentData) {
                return ['error' => 'Користувача не знайдено', 'code' => 404];
            }
            
            $updateFields = [];
            $updateValues = [];
            
            // Проверяем какие поля можно обновлять
            $allowedFields = $this->getAllowedUpdateFields($currentUser);
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $userData)) {
                    switch ($field) {
                        case 'employee_id':
                            if ($userData[$field] !== $currentData[$field] && 
                                $this->isEmployeeIdExists($userData[$field], $userId)) {
                                return ['error' => 'ID працівника вже існує', 'code' => 400];
                            }
                            break;
                        case 'email':
                            if (!empty($userData[$field]) && 
                                $userData[$field] !== $currentData[$field] && 
                                $this->isEmailExists($userData[$field], $userId)) {
                                return ['error' => 'Email вже використовується', 'code' => 400];
                            }
                            break;
                        case 'password':
                            if (!empty($userData[$field])) {
                                $userData[$field] = password_hash($userData[$field], PASSWORD_DEFAULT);
                            } else {
                                continue 2; // Пропускаем пустой пароль
                            }
                            break;
                    }
                    
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $userData[$field];
                }
            }
            
            if (empty($updateFields)) {
                return ['error' => 'Немає даних для оновлення', 'code' => 400];
            }
            
            // Обновляем пользователя
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateValues[] = $userId;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateValues);
            
            $this->db->commit();
            
            // Логируем обновление
            $this->logActivity($currentUser['id'], 'update_user', 
                "Оновлено дані користувача ID: $userId");
            
            return [
                'success' => true,
                'message' => 'Дані користувача успішно оновлено'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            writeErrorLog("Update user error: " . $e->getMessage());
            return ['error' => 'Помилка оновлення користувача', 'code' => 500];
        }
    }
    
    /**
     * Получение пользователя по ID
     */
    public function getUserById($userId, $currentUser = null) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display_name,
                       f.short_name as faculty_name, f.full_name as faculty_full_name,
                       d.short_name as department_name, d.full_name as department_full_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.id = ?
            ");
            
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return null;
            }
            
            // Проверяем права доступа если указан текущий пользователь
            if ($currentUser && !$this->auth->canAccessUser($currentUser, $userId)) {
                return null;
            }
            
            // Убираем пароль из ответа
            unset($user['password_hash']);
            
            return $user;
            
        } catch (Exception $e) {
            writeErrorLog("Get user by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получение списка пользователей с фильтрацией и пагинацией
     */
    public function getUsersList($currentUser, $filters = [], $page = 1, $limit = 50) {
        try {
            $offset = ($page - 1) * $limit;
            
            // Базовые условия
            $whereClause = "WHERE u.is_active = 1";
            $params = [];
            
            // Применяем ограничения по роли
            if ($currentUser['role'] === 'zaviduvach') {
                $whereClause .= " AND u.department_id = ?";
                $params[] = $currentUser['department_id'];
            } elseif ($currentUser['role'] === 'dekanat') {
                $whereClause .= " AND u.faculty_id = ?";
                $params[] = $currentUser['faculty_id'];
            } elseif ($currentUser['role'] === 'vykladach') {
                $whereClause .= " AND u.id = ?";
                $params[] = $currentUser['id'];
            }
            
            // Применяем дополнительные фильтры
            if (!empty($filters['faculty_id'])) {
                $whereClause .= " AND u.faculty_id = ?";
                $params[] = $filters['faculty_id'];
            }
            
            if (!empty($filters['department_id'])) {
                $whereClause .= " AND u.department_id = ?";
                $params[] = $filters['department_id'];
            }
            
            if (!empty($filters['role_id'])) {
                $whereClause .= " AND u.role_id = ?";
                $params[] = $filters['role_id'];
            }
            
            if (!empty($filters['search'])) {
                $whereClause .= " AND (u.full_name LIKE ? OR u.employee_id LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // Получаем пользователей
            $stmt = $this->db->prepare("
                SELECT u.id, u.employee_id, u.full_name, u.email, u.position, u.is_active,
                       u.last_login, u.created_at,
                       r.display_name as role_name,
                       f.short_name as faculty_name,
                       d.short_name as department_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                {$whereClause}
                ORDER BY u.full_name
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            // Получаем общее количество
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total 
                FROM users u 
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                {$whereClause}
            ");
            $countStmt->execute(array_slice($params, 0, -2));
            $total = $countStmt->fetch()['total'];
            
            return [
                'success' => true,
                'data' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Get users list error: " . $e->getMessage());
            return ['error' => 'Помилка отримання списку користувачів', 'code' => 500];
        }
    }
    
    /**
     * Деактивация пользователя
     */
    public function deactivateUser($userId, $currentUser) {
        // Только админ может деактивировать пользователей
        if ($currentUser['role'] !== 'admin') {
            return ['error' => 'Недостатньо прав для деактивації користувача', 'code' => 403];
        }
        
        // Нельзя деактивировать самого себя
        if ($userId == $currentUser['id']) {
            return ['error' => 'Не можна деактивувати самого себе', 'code' => 400];
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Удаляем все активные сессии пользователя
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Логируем деактивацию
            $this->logActivity($currentUser['id'], 'deactivate_user', 
                "Деактивовано користувача ID: $userId");
            
            return [
                'success' => true,
                'message' => 'Користувача успішно деактивовано'
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Deactivate user error: " . $e->getMessage());
            return ['error' => 'Помилка деактивації користувача', 'code' => 500];
        }
    }
    
    /**
     * Активация пользователя
     */
    public function activateUser($userId, $currentUser) {
        // Только админ может активировать пользователей
        if ($currentUser['role'] !== 'admin') {
            return ['error' => 'Недостатньо прав для активації користувача', 'code' => 403];
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Логируем активацию
            $this->logActivity($currentUser['id'], 'activate_user', 
                "Активовано користувача ID: $userId");
            
            return [
                'success' => true,
                'message' => 'Користувача успішно активовано'
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Activate user error: " . $e->getMessage());
            return ['error' => 'Помилка активації користувача', 'code' => 500];
        }
    }
    
    /**
     * Массовый импорт пользователей из CSV
     */
    public function importUsersFromCSV($csvData, $currentUser) {
        // Только админ может импортировать пользователей
        if ($currentUser['role'] !== 'admin') {
            return ['error' => 'Недостатньо прав для імпорту користувачів', 'code' => 403];
        }
        
        try {
            $users = $this->parseUsersCSV($csvData);
            
            if (empty($users)) {
                return ['error' => 'Не вдалося розпарсити CSV файл', 'code' => 400];
            }
            
            $this->db->beginTransaction();
            
            $imported = 0;
            $errors = [];
            
            foreach ($users as $index => $userData) {
                $result = $this->createUserFromImport($userData, $currentUser);
                
                if ($result['success']) {
                    $imported++;
                } else {
                    $errors[] = "Рядок " . ($index + 1) . ": " . $result['message'];
                }
            }
            
            $this->db->commit();
            
            $this->logActivity($currentUser['id'], 'import_users', 
                "Імпортовано користувачів: $imported, помилок: " . count($errors));
            
            return [
                'success' => true,
                'imported' => $imported,
                'errors' => $errors,
                'message' => "Успішно імпортовано $imported користувачів"
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            writeErrorLog("Import users error: " . $e->getMessage());
            return ['error' => 'Помилка імпорту користувачів', 'code' => 500];
        }
    }
    
    /**
     * Получение ролей
     */
    public function getRoles() {
        try {
            $stmt = $this->db->prepare("SELECT id, name, display_name FROM roles ORDER BY display_name");
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Get roles error: " . $e->getMessage());
            return ['error' => 'Помилка отримання ролей', 'code' => 500];
        }
    }
    
    /**
     * Получение факультетов
     */
    public function getFaculties() {
        try {
            $stmt = $this->db->prepare("SELECT id, code, short_name, full_name FROM faculties ORDER BY short_name");
            $stmt->execute();
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Get faculties error: " . $e->getMessage());
            return ['error' => 'Помилка отримання факультетів', 'code' => 500];
        }
    }
    
    /**
     * Получение кафедр факультета
     */
    public function getDepartments($facultyId = null) {
        try {
            $whereClause = $facultyId ? "WHERE faculty_id = ?" : "";
            $params = $facultyId ? [$facultyId] : [];
            
            $stmt = $this->db->prepare("
                SELECT d.id, d.code, d.short_name, d.full_name, d.faculty_id,
                       f.short_name as faculty_name
                FROM departments d
                JOIN faculties f ON d.faculty_id = f.id
                {$whereClause}
                ORDER BY f.short_name, d.short_name
            ");
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Get departments error: " . $e->getMessage());
            return ['error' => 'Помилка отримання кафедр', 'code' => 500];
        }
    }
    
    /**
     * Валидация данных пользователя
     */
    private function validateUserData($userData) {
        if (empty($userData['employee_id'])) {
            return ['valid' => false, 'message' => 'ID працівника обов\'язковий'];
        }
        
        if (empty($userData['full_name'])) {
            return ['valid' => false, 'message' => 'ПІБ обов\'язкове'];
        }
        
        if (empty($userData['role_id'])) {
            return ['valid' => false, 'message' => 'Роль обов\'язкова'];
        }
        
        if (!empty($userData['email']) && !filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'message' => 'Некоректний email'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Проверка существования employee_id
     */
    private function isEmployeeIdExists($employeeId, $excludeUserId = null) {
        $whereClause = "WHERE employee_id = ?";
        $params = [$employeeId];
        
        if ($excludeUserId) {
            $whereClause .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users {$whereClause}");
        $stmt->execute($params);
        
        return $stmt->fetch()['count'] > 0;
    }
    
    /**
     * Проверка существования email
     */
    private function isEmailExists($email, $excludeUserId = null) {
        $whereClause = "WHERE email = ?";
        $params = [$email];
        
        if ($excludeUserId) {
            $whereClause .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users {$whereClause}");
        $stmt->execute($params);
        
        return $stmt->fetch()['count'] > 0;
    }
    
    /**
     * Генерация случайного пароля
     */
    private function generatePassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle($chars), 0, $length);
    }
    
    /**
     * Проверка прав на управление пользователем
     */
    private function canManageUser($currentUser, $targetUserId) {
        // Админ может управлять всеми
        if ($currentUser['role'] === 'admin') {
            return true;
        }
        
        // Викладач может редактировать только себя (ограниченно)
        if ($currentUser['role'] === 'vykladach') {
            return $currentUser['id'] == $targetUserId;
        }
        
        // Остальные роли не могут управлять пользователями
        return false;
    }
    
    /**
     * Получение разрешенных для обновления полей
     */
    private function getAllowedUpdateFields($currentUser) {
        if ($currentUser['role'] === 'admin') {
            return ['employee_id', 'role_id', 'faculty_id', 'department_id', 
                   'full_name', 'email', 'password', 'position', 'is_active'];
        }
        
        if ($currentUser['role'] === 'vykladach') {
            return ['email', 'password']; // Викладач может менять только email и пароль
        }
        
        return [];
    }
    
    /**
     * Парсинг CSV с пользователями
     */
    private function parseUsersCSV($csvData) {
        $users = [];
        $lines = str_getcsv($csvData, "\n");
        
        // Пропускаем заголовок
        $header = array_shift($lines);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $data = str_getcsv($line, ';');
            
            if (count($data) >= 4) {
                $users[] = [
                    'employee_id' => trim($data[0]),
                    'full_name' => trim($data[1]),
                    'email' => !empty($data[2]) ? trim($data[2]) : null,
                    'position' => !empty($data[3]) ? trim($data[3]) : null,
                    'role_id' => 4 // По умолчанию роль викладача
                ];
            }
        }
        
        return $users;
    }
    
    /**
     * Создание пользователя при импорте
     */
    private function createUserFromImport($userData, $currentUser) {
        try {
            // Проверяем уникальность
            if ($this->isEmployeeIdExists($userData['employee_id'])) {
                return ['success' => false, 'message' => 'ID працівника вже існує'];
            }
            
            if (!empty($userData['email']) && $this->isEmailExists($userData['email'])) {
                return ['success' => false, 'message' => 'Email вже використовується'];
            }
            
            // Генерируем пароль
            $password = $this->generatePassword();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $this->db->prepare("
                INSERT INTO users (employee_id, role_id, full_name, email, password_hash, position) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userData['employee_id'],
                $userData['role_id'],
                $userData['full_name'],
                $userData['email'],
                $passwordHash,
                $userData['position']
            ]);
            
            return ['success' => true, 'password' => $password];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Логирование активности
     */
    private function logActivity($userId, $action, $description) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_log (user_id, action, description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $action, $description, $ipAddress, $userAgent]);
        } catch (Exception $e) {
            writeErrorLog("Log activity error: " . $e->getMessage());
        }
    }
}
?>