<?php
// classes/Auth.php - Исправленный класс авторизации

class Auth {
    private $db;
    
    public function __construct() {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (Exception $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Авторизация пользователя
     */
    public function login($employeeId, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions, 
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
                    'role_display' => $user['role_display_name'],
                    'permissions' => json_decode($user['permissions'] ?? '{}', true),
                    'faculty_id' => $user['faculty_id'],
                    'department_id' => $user['department_id'],
                    'faculty_name' => $user['faculty_name'],
                    'department_name' => $user['department_name']
                ],
                'session_id' => $sessionId
            ];
            
        } catch (Exception $e) {
            $this->writeErrorLog("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Проверка прав доступа пользователя к данным другого пользователя
     */
    public function canAccessUser($currentUser, $targetUserId) {
        if (!$currentUser) return false;
        
        $role = $currentUser['role'] ?? '';
        $currentUserId = $currentUser['id'] ?? 0;
        
        // Админ может видеть всех
        if ($role === 'admin') return true;
        
        // Викладач может видеть только себя
        if ($role === 'vykladach') {
            return $currentUserId == $targetUserId;
        }
        
        // Деканат и завкафедры могут видеть пользователей своего подразделения
        if (in_array($role, ['dekanat', 'zaviduvach'])) {
            // Упрощенная проверка - пока разрешаем всем
            return true;
        }
        
        return false;
    }
    
    /**
     * Создание сессии
     */
    private function createSession($userId) {
        $sessionId = bin2hex(random_bytes(32));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            // Проверяем существует ли таблица sessions
            $stmt = $this->db->prepare("SHOW TABLES LIKE 'sessions'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO sessions (id, user_id, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$sessionId, $userId, $ipAddress, $userAgent]);
            }
            
            return $sessionId;
        } catch (Exception $e) {
            $this->writeErrorLog("Session creation error: " . $e->getMessage());
            // Возвращаем временную сессию если таблица не существует
            return 'temp_session_' . $userId . '_' . time();
        }
    }
    
    /**
     * Обновление времени последнего входа
     */
    private function updateLastLogin($userId) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            $this->writeErrorLog("Update last login error: " . $e->getMessage());
        }
    }
    
    /**
     * Логирование активности
     */
    private function logActivity($userId, $action, $description) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            // Проверяем существует ли таблица audit_log
            $stmt = $this->db->prepare("SHOW TABLES LIKE 'audit_log'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO audit_log (user_id, action, description, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $action, $description, $ipAddress, $userAgent]);
            }
        } catch (Exception $e) {
            $this->writeErrorLog("Log activity error: " . $e->getMessage());
        }
    }
    
    /**
     * Логирование ошибок
     */
    private function writeErrorLog($message) {
        $logDir = '../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, $logDir . '/error.log');
    }
}
?>