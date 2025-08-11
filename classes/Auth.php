<?php
// classes/Auth.php

require_once 'config/database.php';
require_once 'config/config.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Авторизация пользователя
     */
    public function login($employeeId, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, r.name as role_name, r.permissions, 
                       f.short_name as faculty_name, d.short_name as department_name
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.employee_id = ? AND u.is_active = 1
            ");
            
            $stmt->execute([$employeeId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $this->logActivity(null, 'failed_login', "Невдала спроба входу для ID: {$employeeId}");
                return false;
            }
            
            // Создаем сессию
            $sessionId = $this->createSession($user['id']);
            
            // Обновляем время последнего входа
            $this->updateLastLogin($user['id']);
            
            // Логируем успешный вход
            $this->logActivity($user['id'], 'login', 'Успішний вхід в систему');
            
            return [
                'user' => [
                    'id' => $user['id'],
                    'employee_id' => $user['employee_id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'position' => $user['position'],
                    'role' => $user['role_name'],
                    'permissions' => json_decode($user['permissions'], true),
                    'faculty_id' => $user['faculty_id'],
                    'department_id' => $user['department_id'],
                    'faculty_name' => $user['faculty_name'],
                    'department_name' => $user['department_name']
                ],
                'session_id' => $sessionId
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Создание сессии
     */
    private function createSession($userId) {
        $sessionId = bin2hex(random_bytes(32));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sessions (id, user_id, ip_address, user_agent) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$sessionId, $userId, $ipAddress, $userAgent]);
            
            return $sessionId;
        } catch (Exception $e) {
            writeErrorLog("Session creation error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Проверка сессии
     */
    public function validateSession($sessionId) {
        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.*, r.name as role_name, r.permissions,
                       f.short_name as faculty_name, d.short_name as department_name
                FROM sessions s
                JOIN users u ON s.user_id = u.id
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE s.id = ? AND u.is_active = 1 
                AND s.last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            
            $stmt->execute([$sessionId, SESSION_EXPIRE_TIME]);
            $session = $stmt->fetch();
            
            if (!$session) {
                return false;
            }
            
            // Обновляем время активности
            $this->updateSessionActivity($sessionId);
            
            return [
                'id' => $session['user_id'],
                'employee_id' => $session['employee_id'],
                'full_name' => $session['full_name'],
                'email' => $session['email'],
                'position' => $session['position'],
                'role' => $session['role_name'],
                'permissions' => json_decode($session['permissions'], true),
                'faculty_id' => $session['faculty_id'],
                'department_id' => $session['department_id'],
                'faculty_name' => $session['faculty_name'],
                'department_name' => $session['department_name']
            ];
            
        } catch (Exception $e) {
            writeErrorLog("Session validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Выход из системы
     */
    public function logout($sessionId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            
            return true;
        } catch (Exception $e) {
            writeErrorLog("Logout error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Смена пароля
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            // Проверяем текущий пароль
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Неправильний поточний пароль'];
            }
            
            // Валидация нового пароля
            if (strlen($newPassword) < 6) {
                return ['success' => false, 'message' => 'Пароль повинен містити мінімум 6 символів'];
            }
            
            // Обновляем пароль
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newPasswordHash, $userId]);
            
            $this->logActivity($userId, 'password_change', 'Змінено пароль');
            
            return ['success' => true, 'message' => 'Пароль успішно змінено'];
            
        } catch (Exception $e) {
            writeErrorLog("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Помилка при зміні паролю'];
        }
    }
    
    /**
     * Проверка прав доступа
     */
    public function hasPermission($user, $action, $resource = null) {
        $permissions = $user['permissions'];
        
        // Админ может все
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Проверяем конкретные права
        if (isset($permissions[$resource]) && in_array($action, $permissions[$resource])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Проверка доступа к данным в зависимости от роли
     */
    public function canAccessUser($currentUser, $targetUserId) {
        // Админ может видеть всех
        if ($currentUser['role'] === 'admin') {
            return true;
        }
        
        // Викладач может видеть только себя
        if ($currentUser['role'] === 'vykladach') {
            return $currentUser['id'] == $targetUserId;
        }
        
        // Завідувач кафедри видит викладачів своей кафедры
        if ($currentUser['role'] === 'zaviduvach') {
            $stmt = $this->db->prepare("
                SELECT department_id FROM users WHERE id = ?
            ");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();
            
            return $targetUser && $targetUser['department_id'] == $currentUser['department_id'];
        }
        
        // Деканат видит викладачів своего факультета
        if ($currentUser['role'] === 'dekanat') {
            $stmt = $this->db->prepare("
                SELECT faculty_id FROM users WHERE id = ?
            ");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();
            
            return $targetUser && $targetUser['faculty_id'] == $currentUser['faculty_id'];
        }
        
        return false;
    }
    
    private function updateLastLogin($userId) {
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    private function updateSessionActivity($sessionId) {
        $stmt = $this->db->prepare("UPDATE sessions SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$sessionId]);
    }
    
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

// classes/AuthMiddleware.php

class AuthMiddleware {
    private $auth;
    
    public function __construct() {
        $this->auth = new Auth();
    }
    
    /**
     * Проверка авторизации для API
     */
    public function authenticate() {
        $sessionId = $this->getSessionId();
        
        if (!$sessionId) {
            $this->sendUnauthorized('Не вказано session ID');
            return false;
        }
        
        $user = $this->auth->validateSession($sessionId);
        
        if (!$user) {
            $this->sendUnauthorized('Недійсна сесія');
            return false;
        }
        
        return $user;
    }
    
    /**
     * Проверка прав доступа
     */
    public function authorize($user, $requiredPermission, $resource = null) {
        if (!$this->auth->hasPermission($user, $requiredPermission, $resource)) {
            $this->sendForbidden('Недостатньо прав доступу');
            return false;
        }
        
        return true;
    }
    
    private function getSessionId() {
        // Ищем session ID в headers или cookies
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        if (isset($_COOKIE['session_id'])) {
            return $_COOKIE['session_id'];
        }
        
        return false;
    }
    
    private function sendUnauthorized($message) {
        http_response_code(401);
        echo json_encode(['error' => $message]);
        exit;
    }
    
    private function sendForbidden($message) {
        http_response_code(403);
        echo json_encode(['error' => $message]);
        exit;
    }
}
?>