<?php
// classes/AchievementsManager.php - Очищена версія без залежності на Auth

require_once '../config/database.php';
require_once '../config/config.php';

class AchievementsManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Отримання досягнень користувача
     * Права доступу вже перевірені middleware
     */
    public function getAchievements($userId, $currentUser) {
        try {
            $stmt = $this->db->prepare("
                SELECT a.*, u.full_name, u.employee_id, u.position,
                       f.short_name as faculty_name, d.short_name as department_name
                FROM achievements a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE a.user_id = ?
            ");
            
            $stmt->execute([$userId]);
            $achievements = $stmt->fetch();
            
            if (!$achievements) {
                // Створюємо пусту запис для користувача
                $achievements = $this->createEmptyAchievements($userId);
            }
            
            return [
                'success' => true,
                'data' => $achievements
            ];
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("Get achievements error: " . $e->getMessage(), 'error');
            return ['error' => 'Помилка отримання даних', 'code' => 500];
        }
    }
    
    /**
     * Оновлення досягнень
     * Права доступу вже перевірені middleware
     */
    public function updateAchievements($userId, $achievementsData, $currentUser) {
        // Додаткова перевірка: викладач може редагувати тільки свої дані
        if ($currentUser['role'] === 'vykladach' && $currentUser['id'] != $userId) {
            return ['error' => 'Ви можете редагувати тільки свої досягнення', 'code' => 403];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Перевіряємо чи існує запис
            $stmt = $this->db->prepare("SELECT id FROM achievements WHERE user_id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            
            $achievementFields = [];
            $values = [];
            
            // Підготовуємо поля для оновлення
            for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
                $field = "achievement_$i";
                $achievementFields[] = "$field = ?";
                $values[] = isset($achievementsData[$field]) ? $this->sanitizeText($achievementsData[$field]) : null;
            }
            
            if ($existing) {
                // Оновлюємо існуючий запис
                $sql = "UPDATE achievements SET " . implode(', ', $achievementFields) . ", last_updated = NOW() WHERE user_id = ?";
                $values[] = $userId;
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
            } else {
                // Створюємо новий запис
                $fieldNames = ['user_id'] + array_map(function($i) { return "achievement_$i"; }, range(1, Config::ACHIEVEMENTS_COUNT));
                $placeholders = str_repeat('?,', count($fieldNames) - 1) . '?';
                $sql = "INSERT INTO achievements (" . implode(', ', $fieldNames) . ") VALUES ($placeholders)";
                
                $insertValues = [$userId] + $values;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($insertValues);
            }
            
            $this->db->commit();
            
            // Логуємо зміни
            $this->logActivity($currentUser['id'], 'update_achievements', 
                "Оновлено досягнення для користувача ID: $userId");
            
            return [
                'success' => true,
                'message' => 'Досягнення успішно збережено'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            Database::getInstance()->writeLog("Update achievements error: " . $e->getMessage(), 'error');
            return ['error' => 'Помилка збереження даних', 'code' => 500];
        }
    }
    
    /**
     * Отримання даних для експорту в JSON
     * Логіка генерації CSV винесена на клієнт
     */
    public function getExportData($userId, $currentUser, $includeEmptyRows = false) {
        $achievementsResult = $this->getAchievements($userId, $currentUser);
        
        if (isset($achievementsResult['error'])) {
            return $achievementsResult;
        }
        
        $achievements = $achievementsResult['data'];
        
        // Формуємо структуровані дані для експорту
        $exportData = [
            'instructor_name' => $achievements['full_name'],
            'instructor_info' => [
                'full_name' => $achievements['full_name'],
                'employee_id' => $achievements['employee_id'],
                'position' => $achievements['position'],
                'faculty_name' => $achievements['faculty_name'],
                'department_name' => $achievements['department_name']
            ],
            'achievements' => []
        ];
        
        // Збираємо досягнення
        $hasData = false;
        for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
            $value = $achievements["achievement_$i"];
            
            if ($value && trim($value)) {
                $exportData['achievements'][$i] = trim($value);
                $hasData = true;
            } elseif ($includeEmptyRows) {
                $exportData['achievements'][$i] = '';
                $hasData = true;
            }
        }
        
        if (!$hasData) {
            return ['error' => 'Немає даних для експорту', 'code' => 400];
        }
        
        return [
            'success' => true,
            'data' => $exportData
        ];
    }
    
    /**
     * Імпорт даних з CSV
     * Права доступу вже перевірені middleware
     */
    public function importFromCSV($userId, $csvContent, $currentUser) {
        try {
            $achievementsData = $this->parseCSV($csvContent);
            
            if (empty($achievementsData)) {
                return ['error' => 'Не вдалося розпарсити CSV файл', 'code' => 400];
            }
            
            return $this->updateAchievements($userId, $achievementsData, $currentUser);
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("CSV import error: " . $e->getMessage(), 'error');
            return ['error' => 'Помилка імпорту CSV', 'code' => 500];
        }
    }
    
    /**
     * Отримання списку користувачів для звітів (з урахуванням прав доступу)
     * Права доступу вже перевірені middleware
     */
    public function getUsersList($currentUser, $page = 1, $limit = 50) {
        try {
            $offset = ($page - 1) * $limit;
            
            // Формуємо запит в залежності від ролі
            $whereClause = "WHERE u.is_active = 1";
            $params = [];
            
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
            
            $stmt = $this->db->prepare("
                SELECT u.id, u.employee_id, u.full_name, u.position,
                       f.short_name as faculty_name, d.short_name as department_name,
                       a.last_updated
                FROM users u
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN achievements a ON u.id = a.user_id
                {$whereClause}
                ORDER BY u.full_name
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            
            $users = $stmt->fetchAll();
            
            // Отримуємо загальну кількість
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total 
                FROM users u 
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
            Database::getInstance()->writeLog("Get users list error: " . $e->getMessage(), 'error');
            return ['error' => 'Помилка отримання списку користувачів', 'code' => 500];
        }
    }
    
    /**
     * Експорт звіту по всім користувачам в CSV
     * Права доступу вже перевірені middleware
     */
    public function exportReport($currentUser, $filters = []) {
        try {
            $whereClause = "WHERE u.is_active = 1";
            $params = [];
            
            // Застосовуємо фільтри доступу по ролях
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
            
            // Додаткові фільтри
            if (!empty($filters['faculty_id'])) {
                $whereClause .= " AND u.faculty_id = ?";
                $params[] = $filters['faculty_id'];
            }
            
            if (!empty($filters['department_id'])) {
                $whereClause .= " AND u.department_id = ?";
                $params[] = $filters['department_id'];
            }
            
            $achievementFields = [];
            for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
                $achievementFields[] = "a.achievement_$i";
            }
            
            $stmt = $this->db->prepare("
                SELECT u.employee_id, u.full_name, u.position,
                       f.short_name as faculty_name, d.short_name as department_name,
                       " . implode(', ', $achievementFields) . ",
                       a.last_updated
                FROM users u
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN achievements a ON u.id = a.user_id
                {$whereClause}
                ORDER BY f.short_name, d.short_name, u.full_name
            ");
            
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            
            // Формуємо CSV
            $csvContent = $this->generateReportCSV($data);
            
            $filename = "report_achievements_" . date('Y-m-d_H-i-s') . ".csv";
            
            return [
                'success' => true,
                'content' => $csvContent,
                'filename' => $filename
            ];
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("Export report error: " . $e->getMessage(), 'error');
            return ['error' => 'Помилка експорту звіту', 'code' => 500];
        }
    }
    
    /**
     * Створення пустого запису досягнень для користувача
     */
    private function createEmptyAchievements($userId) {
        try {
            // Отримуємо інформацію про користувача
            $stmt = $this->db->prepare("
                SELECT u.full_name, u.employee_id, u.position,
                       f.short_name as faculty_name, d.short_name as department_name
                FROM users u
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            $achievements = [
                'user_id' => $userId,
                'full_name' => $user['full_name'],
                'employee_id' => $user['employee_id'],
                'position' => $user['position'],
                'faculty_name' => $user['faculty_name'],
                'department_name' => $user['department_name']
            ];
            
            // Додаємо пусті поля досягнень
            for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
                $achievements["achievement_$i"] = null;
            }
            
            return $achievements;
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("Create empty achievements error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Парсинг CSV файлу
     */
    private function parseCSV($csvContent) {
        $achievements = [];
        
        // Видаляємо BOM якщо є
        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }
        
        $lines = preg_split('/\r\n|\r|\n/', $csvContent);
        
        // Пропускаємо службові рядки (перші 3)
        $dataLines = array_slice($lines, 3);
        
        foreach ($dataLines as $line) {
            if (empty(trim($line))) continue;
            
            // Парсимо рядок з урахуванням CSV формату
            if (preg_match('/^(\d+)\);(.*)$/', $line, $matches)) {
                $number = intval($matches[1]);
                $value = $matches[2];
                
                // Прибираємо лапки і обробляємо екрановані лапки
                if (preg_match('/^"(.*)"$/', $value, $valueMatches)) {
                    $value = str_replace('""', '"', $valueMatches[1]);
                }
                
                if ($number >= 1 && $number <= Config::ACHIEVEMENTS_COUNT) {
                    $achievements["achievement_$number"] = trim($value);
                }
            }
        }
        
        return $achievements;
    }
    
    /**
     * Очищення та санітизація тексту
     */
    private function sanitizeText($text) {
        if (empty($text)) return null;
        
        $text = trim($text);
        
        // Перевіряємо максимальну довжину
        if (strlen($text) > Config::ACHIEVEMENTS_MAX_LENGTH) {
            $text = substr($text, 0, Config::ACHIEVEMENTS_MAX_LENGTH);
        }
        
        return $text;
    }
    
    /**
     * Генерація CSV для звіту
     */
    private function generateReportCSV($data) {
        $csv = "ID працівника;ПІБ;Посада;Факультет;Кафедра;Останнє оновлення;";
        
        // Додаємо заголовки для досягнень
        for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
            $csv .= "Досягнення $i;";
        }
        $csv .= "\n";
        
        foreach ($data as $row) {
            $csv .= '"' . ($row['employee_id'] ?? '') . '";';
            $csv .= '"' . ($row['full_name'] ?? '') . '";';
            $csv .= '"' . ($row['position'] ?? '') . '";';
            $csv .= '"' . ($row['faculty_name'] ?? '') . '";';
            $csv .= '"' . ($row['department_name'] ?? '') . '";';
            $csv .= '"' . ($row['last_updated'] ?? '') . '";';
            
            for ($i = 1; $i <= Config::ACHIEVEMENTS_COUNT; $i++) {
                $achievement = $row["achievement_$i"] ?? '';
                $csv .= '"' . str_replace('"', '""', $achievement) . '";';
            }
            $csv .= "\n";
        }
        
        return $csv;
    }
    
    /**
     * Логування активності
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
            Database::getInstance()->writeLog("Log activity error: " . $e->getMessage(), 'error');
        }
    }
}
?>