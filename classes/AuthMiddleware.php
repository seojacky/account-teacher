<?php
// classes/AuthMiddleware.php - Middleware авторизації

require_once '../config/database.php';
require_once 'Auth.php';

class AuthMiddleware {
    private $db;
    private $auth;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auth = new Auth();
    }
    
    /**
     * Перевірка авторизації користувача
     * @return array|false Дані користувача або false
     */
    public function authenticate() {
        $authHeader = $this->getAuthorizationHeader();
        
        if (!$authHeader) {
            return false;
        }
        
        // Перевіряємо формат Bearer token
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return false;
        }
        
        $sessionId = $matches[1];
        
        if (empty($sessionId) || strlen($sessionId) !== 64) {
            return false;
        }
        
        return $this->validateSession($sessionId);
    }
    
    /**
     * Основний middleware для перевірки авторизації
     * @param callable $next Наступний обробник
     * @return mixed
     */
    public function handle($next) {
        $user = $this->authenticate();
        
        if (!$user) {
            $this->sendUnauthorizedResponse();
            return false;
        }
        
        return $next($user);
    }
    
    /**
     * Middleware для перевірки ролей
     * @param array $allowedRoles Дозволені ролі
     * @param callable $next Наступний обробник
     * @return mixed
     */
    public function requireRole($allowedRoles, $next) {
        $user = $this->authenticate();
        
        if (!$user) {
            $this->sendUnauthorizedResponse();
            return false;
        }
        
        if (!in_array($user['role'], $allowedRoles)) {
            $this->sendForbiddenResponse();
            return false;
        }
        
        return $next($user);
    }
    
    /**
     * Middleware для перевірки прав доступу до користувача
     * @param int $targetUserId ID цільового користувача
     * @param callable $next Наступний обробник
     * @return mixed
     */
    public function requireUserAccess($targetUserId, $next) {
        $user = $this->authenticate();
        
        if (!$user) {
            $this->sendUnauthorizedResponse();
            return false;
        }
        
        if (!$this->auth->canAccessUser($user, $targetUserId)) {
            $this->sendForbiddenResponse();
            return false;
        }
        
        return $next($user);
    }
    
    /**
     * Отримання заголовка авторизації
     * @return string|null
     */
    private function getAuthorizationHeader() {
        // Перевіряємо різні способи отримання заголовка
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        // Альтернативний спосіб через getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }
        
        return null;
    }
    
    /**
     * Валідація сесії користувача
     * @param string $sessionId ID сесії
     * @return array|false Дані користувача або false
     */
    private function validateSession($sessionId) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions,
                       f.short_name as faculty_name, d.short_name as department_name,
                       s.last_activity
                FROM sessions s
                JOIN users u ON s.user_id = u.id
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN faculties f ON u.faculty_id = f.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE s.id = ? AND u.is_active = 1
            ");
            
            $stmt->execute([$sessionId]);
            $result = $stmt->fetch();
            
            if (!$result) {
                return false;
            }
            
            // Перевіряємо, не істекла ли сесія (24 години)
            $lastActivity = strtotime($result['last_activity']);
            $now = time();
            $timeDiff = $now - $lastActivity;
            
            if ($timeDiff > 24 * 60 * 60) { // 24 години в секундах
                // Сесія істекла, видаляємо її
                $deleteStmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
                $deleteStmt->execute([$sessionId]);
                return false;
            }
            
            // Оновлюємо час останньої активності
            $updateStmt = $this->db->prepare("UPDATE sessions SET last_activity = NOW() WHERE id = ?");
            $updateStmt->execute([$sessionId]);
            
            // Повертаємо дані користувача
            return [
                'id' => $result['id'],
                'employee_id' => $result['employee_id'],
                'full_name' => $result['full_name'],
                'email' => $result['email'],
                'position' => $result['position'],
                'role' => $result['role_name'],
                'role_display' => $result['role_display_name'],
                'permissions' => json_decode($result['permissions'] ?? '{}', true),
                'faculty_id' => $result['faculty_id'],
                'department_id' => $result['department_id'],
                'faculty_name' => $result['faculty_name'],
                'department_name' => $result['department_name']
            ];
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("AuthMiddleware validateSession error: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Відправка відповіді про неавторизований доступ
     */
    private function sendUnauthorizedResponse() {
        // Використовуємо функцію з api/index.php
        if (function_exists('sendError')) {
            sendError('Authentication required', 401);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * Відправка відповіді про заборонений доступ
     */
    private function sendForbiddenResponse() {
        // Використовуємо функцію з api/index.php
        if (function_exists('sendError')) {
            sendError('Access forbidden', 403);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
?>