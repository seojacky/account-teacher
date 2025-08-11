<?php
// api/index.php - З діагностикою авторизації

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем preflight запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Підключаємо файли один раз
static $initialized = false;
if (!$initialized) {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../classes/Auth.php';
    $initialized = true;
}

try {
    // Отримуємо шлях
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    $parts = explode('/', $path);
    
    // Убираем 'api' из пути если есть
    if ($parts[0] === 'api') {
        array_shift($parts);
    }
    
    $parts = array_filter($parts);
    $parts = array_values($parts);
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Простий роутинг
    if (count($parts) >= 2 && $parts[0] === 'auth') {
        if ($parts[1] === 'login' && $method === 'POST') {
            handleLogin();
        } elseif ($parts[1] === 'me' && $method === 'GET') {
            handleGetMe();
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
        
        // Діагностика
        $debug = [
            'step' => 1,
            'received_data' => $data,
            'employee_id' => $data['employee_id'] ?? 'missing',
            'password_length' => isset($data['password']) ? strlen($data['password']) : 0
        ];
        
        if (empty($data['employee_id']) || empty($data['password'])) {
            sendError('Employee ID and password are required', 400);
            return;
        }
        
        $debug['step'] = 2;
        $debug['message'] = 'Перевіряємо користувача в БД';
        
        // Перевіряємо користувача напряму в БД
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.permissions
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.employee_id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$data['employee_id']]);
        $user = $stmt->fetch();
        
        $debug['step'] = 3;
        $debug['user_found'] = $user ? true : false;
        
        if (!$user) {
            $debug['error'] = 'Користувач не знайдений';
            sendError('User not found: ' . json_encode($debug), 401);
            return;
        }
        
        $debug['step'] = 4;
        $debug['stored_hash'] = substr($user['password_hash'], 0, 20) . '...';
        $debug['password_verify'] = password_verify($data['password'], $user['password_hash']);
        
        if (!password_verify($data['password'], $user['password_hash'])) {
            $debug['error'] = 'Пароль не співпадає';
            sendError('Password mismatch: ' . json_encode($debug), 401);
            return;
        }
        
        $debug['step'] = 5;
        $debug['message'] = 'Авторизація успішна!';
        
        // Успішна авторизація
        sendSuccess([
            'user' => [
                'id' => $user['id'],
                'employee_id' => $user['employee_id'],
                'full_name' => $user['full_name'],
                'role' => $user['role_name']
            ],
            'session_id' => 'test_session_' . time(),
            'debug' => $debug
        ]);
        
    } catch (Exception $e) {
        sendError('Login error: ' . $e->getMessage(), 500);
    }
}

function handleGetMe() {
    sendSuccess([
        'user' => [
            'id' => 1, 
            'name' => 'Test User',
            'message' => 'API auth/me працює!'
        ]
    ]);
}

function sendSuccess($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>