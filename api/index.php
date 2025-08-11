<?php
// api/index.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем preflight запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/Auth.php';
require_once '../classes/AuthMiddleware.php';
require_once '../classes/UserManager.php';
require_once '../classes/AchievementsManager.php';

class ApiRouter {
    private $method;
    private $path;
    private $auth;
    private $middleware;
    private $userManager;
    private $achievementsManager;
    
    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path = $this->getPath();
        $this->auth = new Auth();
        $this->middleware = new AuthMiddleware();
        $this->userManager = new UserManager();
        $this->achievementsManager = new AchievementsManager();
    }
    
    public function route() {
        try {
            switch ($this->path[0]) {
                case 'auth':
                    $this->handleAuth();
                    break;
                case 'users':
                    $this->handleUsers();
                    break;
                case 'achievements':
                    $this->handleAchievements();
                    break;
                case 'reports':
                    $this->handleReports();
                    break;
                case 'system':
                    $this->handleSystem();
                    break;
                default:
                    $this->sendError('Endpoint not found', 404);
            }
        } catch (Exception $e) {
            writeErrorLog("API Router error: " . $e->getMessage());
            $this->sendError('Internal server error', 500);
        }
    }
    
    /**
     * Обработка авторизации
     */
    private function handleAuth() {
        switch ($this->method) {
            case 'POST':
                if ($this->path[1] === 'login') {
                    $this->login();
                } elseif ($this->path[1] === 'logout') {
                    $this->logout();
                } elseif ($this->path[1] === 'change-password') {
                    $this->changePassword();
                } else {
                    $this->sendError('Invalid auth endpoint', 404);
                }
                break;
            case 'GET':
                if ($this->path[1] === 'me') {
                    $this->getCurrentUser();
                } else {
                    $this->sendError('Invalid auth endpoint', 404);
                }
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * Обработка пользователей
     */
    private function handleUsers() {
        $user = $this->middleware->authenticate();
        
        switch ($this->method) {
            case 'GET':
                if (empty($this->path[1])) {
                    $this->getUsersList($user);
                } elseif ($this->path[1] === 'roles') {
                    $this->getRoles($user);
                } elseif ($this->path[1] === 'faculties') {
                    $this->getFaculties($user);
                } elseif ($this->path[1] === 'departments') {
                    $this->getDepartments($user);
                } elseif (is_numeric($this->path[1])) {
                    $this->getUser($this->path[1], $user);
                } else {
                    $this->sendError('Invalid users endpoint', 404);
                }
                break;
            case 'POST':
                if (empty($this->path[1])) {
                    $this->createUser($user);
                } elseif ($this->path[1] === 'import') {
                    $this->importUsers($user);
                } else {
                    $this->sendError('Invalid users endpoint', 404);
                }
                break;
            case 'PUT':
                if (is_numeric($this->path[1])) {
                    $this->updateUser($this->path[1], $user);
                } else {
                    $this->sendError('Invalid users endpoint', 404);
                }
                break;
            case 'DELETE':
                if (is_numeric($this->path[1])) {
                    $this->deleteUser($this->path[1], $user);
                } else {
                    $this->sendError('Invalid users endpoint', 404);
                }
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * Обработка достижений
     */
    private function handleAchievements() {
        $user = $this->middleware->authenticate();
        
        switch ($this->method) {
            case 'GET':
                if (is_numeric($this->path[1])) {
                    if ($this->path[2] === 'export') {
                        $this->exportAchievements($this->path[1], $user);
                    } else {
                        $this->getAchievements($this->path[1], $user);
                    }
                } else {
                    $this->sendError('Invalid achievements endpoint', 404);
                }
                break;
            case 'POST':
                if (is_numeric($this->path[1])) {
                    if ($this->path[2] === 'import') {
                        $this->importAchievements($this->path[1], $user);
                    } else {
                        $this->sendError('Invalid achievements endpoint', 404);
                    }
                } else {
                    $this->sendError('Invalid achievements endpoint', 404);
                }
                break;
            case 'PUT':
                if (is_numeric($this->path[1])) {
                    $this->updateAchievements($this->path[1], $user);
                } else {
                    $this->sendError('Invalid achievements endpoint', 404);
                }
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * Обработка отчетов
     */
    private function handleReports() {
        $user = $this->middleware->authenticate();
        
        switch ($this->method) {
            case 'GET':
                if ($this->path[1] === 'export') {
                    $this->exportReport($user);
                } elseif ($this->path[1] === 'statistics') {
                    $this->getStatistics($user);
                } else {
                    $this->sendError('Invalid reports endpoint', 404);
                }
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * Обработка системных запросов
     */
    private function handleSystem() {
        $user = $this->middleware->authenticate();
        
        if ($user['role'] !== 'admin') {
            $this->sendError('Access denied', 403);
            return;
        }
        
        switch ($this->method) {
            case 'GET':
                if ($this->path[1] === 'logs') {
                    $this->getSystemLogs($user);
                } elseif ($this->path[1] === 'status') {
                    $this->getSystemStatus($user);
                } else {
                    $this->sendError('Invalid system endpoint', 404);
                }
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    // === AUTH METHODS ===
    
    private function login() {
        $data = $this->getJsonInput();
        
        if (empty($data['employee_id']) || empty($data['password'])) {
            $this->sendError('Employee ID and password are required', 400);
            return;
        }
        
        $result = $this->auth->login($data['employee_id'], $data['password']);
        
        if ($result) {
            $this->sendSuccess($result);
        } else {
            $this->sendError('Invalid credentials', 401);
        }
    }
    
    private function logout() {
        $sessionId = $this->getSessionId();
        
        if ($sessionId) {
            $this->auth->logout($sessionId);
        }
        
        $this->sendSuccess(['message' => 'Logged out successfully']);
    }
    
    private function changePassword() {
        $user = $this->middleware->authenticate();
        $data = $this->getJsonInput();
        
        if (empty($data['current_password']) || empty($data['new_password'])) {
            $this->sendError('Current and new passwords are required', 400);
            return;
        }
        
        $result = $this->auth->changePassword(
            $user['id'], 
            $data['current_password'], 
            $data['new_password']
        );
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['message'], 400);
        }
    }
    
    private function getCurrentUser() {
        $user = $this->middleware->authenticate();
        $this->sendSuccess(['user' => $user]);
    }
    
    // === USER METHODS ===
    
    private function getUsersList($currentUser) {
        $filters = $_GET;
        $page = intval($_GET['page'] ?? 1);
        $limit = min(intval($_GET['limit'] ?? 50), MAX_PAGE_SIZE);
        
        $result = $this->userManager->getUsersList($currentUser, $filters, $page, $limit);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function getUser($userId, $currentUser) {
        $user = $this->userManager->getUserById($userId, $currentUser);
        
        if ($user) {
            $this->sendSuccess(['user' => $user]);
        } else {
            $this->sendError('User not found', 404);
        }
    }
    
    private function createUser($currentUser) {
        $data = $this->getJsonInput();
        $result = $this->userManager->createUser($data, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function updateUser($userId, $currentUser) {
        $data = $this->getJsonInput();
        $result = $this->userManager->updateUser($userId, $data, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function deleteUser($userId, $currentUser) {
        $result = $this->userManager->deactivateUser($userId, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function importUsers($currentUser) {
        if (!isset($_FILES['file'])) {
            $this->sendError('No file uploaded', 400);
            return;
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->sendError('File upload error', 400);
            return;
        }
        
        $csvData = file_get_contents($file['tmp_name']);
        $result = $this->userManager->importUsersFromCSV($csvData, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function getRoles($currentUser) {
        $result = $this->userManager->getRoles();
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function getFaculties($currentUser) {
        $result = $this->userManager->getFaculties();
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function getDepartments($currentUser) {
        $facultyId = $_GET['faculty_id'] ?? null;
        $result = $this->userManager->getDepartments($facultyId);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    // === ACHIEVEMENTS METHODS ===
    
    private function getAchievements($userId, $currentUser) {
        $result = $this->achievementsManager->getAchievements($userId, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function updateAchievements($userId, $currentUser) {
        $data = $this->getJsonInput();
        $result = $this->achievementsManager->updateAchievements($userId, $data, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function exportAchievements($userId, $currentUser) {
        $encoding = $_GET['encoding'] ?? 'utf8bom';
        $includeEmpty = isset($_GET['include_empty']) && $_GET['include_empty'] === 'true';
        
        $result = $this->achievementsManager->generateCSV($userId, $currentUser, $encoding, $includeEmpty);
        
        if ($result['success']) {
            // Отправляем файл
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            echo $result['content'];
            exit;
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function importAchievements($userId, $currentUser) {
        if (!isset($_FILES['file'])) {
            $this->sendError('No file uploaded', 400);
            return;
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->sendError('File upload error', 400);
            return;
        }
        
        $csvData = file_get_contents($file['tmp_name']);
        $result = $this->achievementsManager->importFromCSV($userId, $csvData, $currentUser);
        
        if ($result['success']) {
            $this->sendSuccess($result);
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    // === REPORTS METHODS ===
    
    private function exportReport($currentUser) {
        $filters = $_GET;
        $result = $this->achievementsManager->exportReport($currentUser, $filters);
        
        if ($result['success']) {
            // Отправляем файл
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            echo $result['content'];
            exit;
        } else {
            $this->sendError($result['error'], $result['code']);
        }
    }
    
    private function getStatistics($currentUser) {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Базовая статистика
            $stats = [];
            
            // Общее количество пользователей
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
            $stmt->execute();
            $stats['total_users'] = $stmt->fetch()['total'];
            
            // Количество пользователей с заполненными достижениями
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT a.user_id) as with_achievements 
                FROM achievements a 
                JOIN users u ON a.user_id = u.id 
                WHERE u.is_active = 1
            ");
            $stmt->execute();
            $stats['users_with_achievements'] = $stmt->fetch()['with_achievements'];
            
            // Статистика по ролям
            $stmt = $db->prepare("
                SELECT r.display_name, COUNT(u.id) as count 
                FROM roles r 
                LEFT JOIN users u ON r.id = u.role_id AND u.is_active = 1 
                GROUP BY r.id, r.display_name
            ");
            $stmt->execute();
            $stats['by_role'] = $stmt->fetchAll();
            
            // Статистика по факультетам
            $stmt = $db->prepare("
                SELECT f.short_name, COUNT(u.id) as count 
                FROM faculties f 
                LEFT JOIN users u ON f.id = u.faculty_id AND u.is_active = 1 
                GROUP BY f.id, f.short_name 
                ORDER BY count DESC
            ");
            $stmt->execute();
            $stats['by_faculty'] = $stmt->fetchAll();
            
            $this->sendSuccess(['statistics' => $stats]);
            
        } catch (Exception $e) {
            writeErrorLog("Get statistics error: " . $e->getMessage());
            $this->sendError('Error getting statistics', 500);
        }
    }
    
    // === SYSTEM METHODS ===
    
    private function getSystemLogs($currentUser) {
        try {
            $db = Database::getInstance()->getConnection();
            $page = intval($_GET['page'] ?? 1);
            $limit = min(intval($_GET['limit'] ?? 50), MAX_PAGE_SIZE);
            $offset = ($page - 1) * $limit;
            
            $stmt = $db->prepare("
                SELECT al.*, u.full_name as user_name 
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $logs = $stmt->fetchAll();
            
            // Получаем общее количество
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM audit_log");
            $stmt->execute();
            $total = $stmt->fetch()['total'];
            
            $this->sendSuccess([
                'data' => $logs,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            writeErrorLog("Get system logs error: " . $e->getMessage());
            $this->sendError('Error getting system logs', 500);
        }
    }
    
    private function getSystemStatus($currentUser) {
        try {
            $db = Database::getInstance()->getConnection();
            
            $status = [
                'database' => 'connected',
                'version' => APP_VERSION,
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'disk_space' => disk_free_space('.'),
                'active_sessions' => 0
            ];
            
            // Количество активных сессий
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM sessions 
                WHERE last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([SESSION_EXPIRE_TIME]);
            $status['active_sessions'] = $stmt->fetch()['count'];
            
            $this->sendSuccess(['status' => $status]);
            
        } catch (Exception $e) {
            writeErrorLog("Get system status error: " . $e->getMessage());
            $this->sendError('Error getting system status', 500);
        }
    }
    
    // === HELPER METHODS ===
    
    private function getPath() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = trim($path, '/');
        $parts = explode('/', $path);
        
        // Убираем 'api' из пути если есть
        if ($parts[0] === 'api') {
            array_shift($parts);
        }
        
        return array_filter($parts);
    }
    
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
    
    private function getSessionId() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        return $_COOKIE['session_id'] ?? null;
    }
    
    private function sendSuccess($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Инициализация и запуск роутера
try {
    $router = new ApiRouter();
    $router->route();
} catch (Exception $e) {
    writeErrorLog("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
}
?>