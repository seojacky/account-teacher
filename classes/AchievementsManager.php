<?php
// classes/AchievementsManager.php

require_once '../config/database.php';
require_once 'Auth.php';

class AchievementsManager {
    private $db;
    private $auth;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auth = new Auth();
    }
    
    /**
     * Получение достижений пользователя
     */
    public function getAchievements($userId, $currentUser) {
        // Проверяем право доступа к данным пользователя
        if (!$this->auth->canAccessUser($currentUser, $userId)) {
            return ['error' => 'Доступ заборонено', 'code' => 403];
        }
        
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
                // Создаем пустую запись для пользователя
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
     * Обновление достижений
     */
    public function updateAchievements($userId, $achievementsData, $currentUser) {
        // Проверяем права доступа
        if (!$this->auth->canAccessUser($currentUser, $userId)) {
            return ['error' => 'Доступ заборонено', 'code' => 403];
        }
        
        // Викладач может редактировать только свои данные
        if ($currentUser['role'] === 'vykladach' && $currentUser['id'] != $userId) {
            return ['error' => 'Ви можете редагувати тільки свої досягнення', 'code' => 403];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Проверяем существует ли запись
            $stmt = $this->db->prepare("SELECT id FROM achievements WHERE user_id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            
            $achievementFields = [];
            $values = [];
            
            // Подготавливаем поля для обновления
            for ($i = 1; $i <= 20; $i++) {
                $field = "achievement_$i";
                $achievementFields[] = "$field = ?";
                $values[] = isset($achievementsData[$field]) ? $this->sanitizeText($achievementsData[$field]) : null;
            }
            
            if ($existing) {
                // Обновляем существующую запись
                $sql = "UPDATE achievements SET " . implode(', ', $achievementFields) . " WHERE user_id = ?";
                $values[] = $userId;
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
            } else {
                // Создаем новую запись
                $fieldNames = ['user_id'] + array_map(function($i) { return "achievement_$i"; }, range(1, 20));
                $placeholders = str_repeat('?,', count($fieldNames) - 1) . '?';
                $sql = "INSERT INTO achievements (" . implode(', ', $fieldNames) . ") VALUES ($placeholders)";
                
                $insertValues = [$userId] + $values;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($insertValues);
            }
            
            $this->db->commit();
            
            // Логируем изменения
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
     * Генерация CSV файла с достижениями
     */
    public function generateCSV($userId, $currentUser, $encoding = 'utf8bom', $includeEmptyRows = false) {
        $achievementsResult = $this->getAchievements($userId, $currentUser);
        
        if (isset($achievementsResult['error'])) {
            return $achievementsResult;
        }
        
        $achievements = $achievementsResult['data'];
        $instructorName = $achievements['full_name'];
        
        // Формируем содержимое CSV
        $csvContent = "© 2025 Скрипт розроблено Калінський Є.О. Всі права захищені. https://t.me/big_jacky\n";
        $csvContent .= "Викладач: {$instructorName}\n";
        $csvContent .= "Тип;Інформація\n";
        
        $hasData = false;
        
        for ($i = 1; $i <= 20; $i++) {
            $value = $achievements["achievement_$i"];
            
            if ($value && trim($value)) {
                $cleanValue = $this->cleanTextForCSV($value);
                $csvContent .= "{$i});\"" . str_replace('"', '""', $cleanValue) . "\"\n";
                $hasData = true;
            } elseif ($includeEmptyRows) {
                $csvContent .= "{$i});\n";
                $hasData = true;
            }
        }
        
        if (!$hasData) {
            return ['error' => 'Немає даних для експорту', 'code' => 400];
        }
        
        // Применяем кодировку
        $finalContent = $this->applyEncoding($csvContent, $encoding);
        
        // Генерируем имя файла
        $date = date('Y-m-d');
        $transliteratedName = $this->transliterateUkrainian($instructorName);
        $filename = "dosyagnennya-{$transliteratedName}_{$date}.csv";
        
        return [
            'success' => true,
            'content' => $finalContent,
            'filename' => $filename,
            'encoding' => $encoding
        ];
    }
    
    /**
     * Импорт данных из CSV
     */
    public function importFromCSV($userId, $csvContent, $currentUser) {
        // Проверяем права доступа
        if (!$this->auth->canAccessUser($currentUser, $userId)) {
            return ['error' => 'Доступ заборонено', 'code' => 403];
        }
        
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
     * Получение списка пользователей для отчетов (с учетом прав доступа)
     */
    public function getUsersList($currentUser, $page = 1, $limit = 50) {
        try {
            $offset = ($page - 1) * $limit;
            
            // Формируем запрос в зависимости от роли
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
            
            // Получаем общее количество
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
     * Экспорт отчета по всем пользователям в CSV
     */
    public function exportReport($currentUser, $filters = []) {
        try {
            $whereClause = "WHERE u.is_active = 1";
            $params = [];
            
            // Применяем фильтры доступа по ролям
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
            
            // Дополнительные фильтры
            if (!empty($filters['faculty_id'])) {
                $whereClause .= " AND u.faculty_id = ?";
                $params[] = $filters['faculty_id'];
            }
            
            if (!empty($filters['department_id'])) {
                $whereClause .= " AND u.department_id = ?";
                $params[] = $filters['department_id'];
            }
            
            $stmt = $this->db->prepare("
                SELECT u.employee_id, u.full_name, u.position,
                       f.short_name as faculty_name, d.short_name as department_name,
                       a.achievement_1, a.achievement_2, a.achievement_3, a.achievement_4, a.achievement_5,
                       a.achievement_6, a.achievement_7, a.achievement_8, a.achievement_9, a.achievement_10,
                       a.achievement_11, a.achievement_12, a.achievement_13, a.achievement_14, a.achievement_15,
                       a.achievement_16, a.achievement_17, a.achievement_18, a.achievement_19, a.achievement_20,
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
            
            // Формируем CSV
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
     * Создание пустой записи достижений для пользователя
     */
    private function createEmptyAchievements($userId) {
        try {
            // Получаем информацию о пользователе
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
            
            // Добавляем пустые поля достижений
            for ($i = 1; $i <= 20; $i++) {
                $achievements["achievement_$i"] = null;
            }
            
            return $achievements;
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("Create empty achievements error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Парсинг CSV файла
     */
    private function parseCSV($csvContent) {
        $achievements = [];
        
        // Удаляем BOM если есть
        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }
        
        $lines = preg_split('/\r\n|\r|\n/', $csvContent);
        
        // Пропускаем служебные строки (первые 3)
        $dataLines = array_slice($lines, 3);
        
        foreach ($dataLines as $line) {
            if (empty(trim($line))) continue;
            
            // Парсим строку с учетом CSV формата
            if (preg_match('/^(\d+)\);(.*)$/', $line, $matches)) {
                $number = intval($matches[1]);
                $value = $matches[2];
                
                // Убираем кавычки и обрабатываем экранированные кавычки
                if (preg_match('/^"(.*)"$/', $value, $valueMatches)) {
                    $value = str_replace('""', '"', $valueMatches[1]);
                }
                
                if ($number >= 1 && $number <= 20) {
                    $achievements["achievement_$number"] = trim($value);
                }
            }
        }
        
        return $achievements;
    }
    
    /**
     * Очистка текста для CSV
     */
    private function cleanTextForCSV($text) {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
    
    /**
     * Применение кодировки к содержимому
     */
    private function applyEncoding($content, $encoding) {
        switch ($encoding) {
            case 'utf8bom':
                return "\xEF\xBB\xBF" . $content;
            case 'windows1251':
                return iconv('UTF-8', 'Windows-1251//IGNORE', $content);
            default:
                return $content;
        }
    }
    
    /**
     * Транслитерация украинского текста
     */
    private function transliterateUkrainian($text) {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g',
            'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
            'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
            'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ю' => 'iu', 'я' => 'ia', 'ь' => '', "'" => '', '"' => '', '`' => '',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'H', 'Ґ' => 'G',
            'Д' => 'D', 'Е' => 'E', 'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z',
            'И' => 'Y', 'І' => 'I', 'Ї' => 'Yi', 'Й' => 'Y', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P',
            'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
            'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch',
            'Ю' => 'Yu', 'Я' => 'Ya', 'Ь' => '', "'" => '', '"' => '', '`' => ''
        ];
        
        $result = strtr($text, $map);
        $result = preg_replace('/[^\w\s-]/', '', $result);
        $result = preg_replace('/\s+/', '_', trim($result));
        
        return $result;
    }
    
    /**
     * Очистка и санитизация текста
     */
    private function sanitizeText($text) {
        if (empty($text)) return null;
        return trim($text);
    }
    
    /**
     * Генерация CSV для отчета
     */
    private function generateReportCSV($data) {
        $csv = "ID працівника;ПІБ;Посада;Факультет;Кафедра;Останнє оновлення;";
        
        // Добавляем заголовки для достижений
        for ($i = 1; $i <= 20; $i++) {
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
            
            for ($i = 1; $i <= 20; $i++) {
                $achievement = $row["achievement_$i"] ?? '';
                $csv .= '"' . str_replace('"', '""', $achievement) . '";';
            }
            $csv .= "\n";
        }
        
        return $csv;
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
            Database::getInstance()->writeLog("Log activity error: " . $e->getMessage(), 'error');
        }
    }
}
?>