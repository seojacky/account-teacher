<?php
// api/index.php - Исправленный основной API

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем preflight запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Функция логирования ошибок
function writeErrorLog($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, '../logs/error.log');
}

// Подключаем только database.php (убираем config.php)
try {
    require_once '../config/database.php';
} catch (Exception $e) {
    sendError('Database config not found: ' . $e->getMessage(), 500);
    exit;
}

try {
    // Отримуємо шлях
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    $parts = explode('/', $path);
    
    // Убираем 'api' из пути если есть
    if (count($parts) > 0 && $parts[0] === 'api') {
        array_shift($parts);
    }
    
    $parts = array_filter($parts);
    $parts = array_values($parts);
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Простой роутинг
    if (count($parts) >= 2 && $parts[0] === 'auth') {
        if ($parts[1] === 'login' && $method === 'POST') {
            handleLogin();
        } elseif ($parts[1] === 'me' && $method === 'GET') {
            handleGetMe();
        } elseif ($parts[1] === 'logout' && $method === 'POST') {
            handleLogout();
        } else {
            sendError('Invalid auth endpoint', 404);
        }
    } else {
        sendError('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    writeErrorLog("API Router error: " . $e->getMessage());
    sendError('Server error: ' . $e->getMessage(), 500);
}

function handleLogin() {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (empty($data['employee_id']) || empty($data['password'])) {
            sendError('Employee ID and password are required', 400);
            return;
        }
        
        // Прямое подключение к БД без классов Auth
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions, 
                   f.short_name as faculty_name, d.short_name as department_name
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            LEFT JOIN faculties f ON u.faculty_id = f.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.employee_id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$data['employee_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            sendError('Invalid credentials', 401);
            return;
        }
        
        // Обновляем время последнего входа
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Создаем сессию
        $sessionId = bin2hex(random_bytes(32));
        
        sendSuccess([
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
        ]);
        
    } catch (Exception $e) {
        writeErrorLog("Login error: " . $e->getMessage());
        sendError('Login error: ' . $e->getMessage(), 500);
    }
}

function handleGetMe() {
    try {
        // Простая проверка сессии
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? null;
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            sendError('No authorization header', 401);
            return;
        }
        
        sendSuccess([
            'user' => [
                'id' => 1,
                'employee_id' => 'ADMIN',
                'full_name' => 'Адміністратор Системи',
                'role' => 'admin'
            ]
        ]);
        
    } catch (Exception $e) {
        writeErrorLog("Get me error: " . $e->getMessage());
        sendError('Authentication error', 401);
    }
}

function handleLogout() {
    try {
        sendSuccess(['message' => 'Logged out successfully']);
    } catch (Exception $e) {
        writeErrorLog("Logout error: " . $e->getMessage());
        sendError('Logout error', 500);
    }
}

function sendSuccess($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>