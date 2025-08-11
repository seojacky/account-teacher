<?php
// classes/Auth.php

// Видаляємо require_once тут - файли підключаються в api/index.php

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
            $this->writeErrorLog("Login error: " . $e->getMessage());
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
            $this->writeErrorLog("Session creation error: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function updateLastLogin($userId) {
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
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
            $this->writeErrorLog("Log activity error: " . $e->getMessage());
        }
    }
    
    private function writeErrorLog($message) {
        if (function_exists('writeErrorLog')) {
            writeErrorLog($message);
        } else {
            error_log($message);
        }
    }
}
?>