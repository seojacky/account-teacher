<?php
// api/debug_login.php - Исправленная отладочная версия

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function writeErrorLog($message) {
    $logDir = '../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, $logDir . '/error.log');
}

try {
    // Проверяем существование файлов с разными путями
    $configPaths = ['../config/database.php', './config/database.php', 'config/database.php'];
    $authPaths = ['../classes/Auth.php', './classes/Auth.php', 'classes/Auth.php'];
    
    $configExists = false;
    $configPath = '';
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            $configExists = true;
            $configPath = $path;
            break;
        }
    }
    
    $authExists = false;
    $authPath = '';
    foreach ($authPaths as $path) {
        if (file_exists($path)) {
            $authExists = true;
            $authPath = $path;
            break;
        }
    }
    
    $debug = [
        'step' => 0,
        'files_check' => [
            'config_database' => $configExists,
            'config_path' => $configPath,
            'auth_class' => $authExists,
            'auth_path' => $authPath
        ],
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'current_dir' => __DIR__,
        'script_filename' => __FILE__
    ];
    
    if (!$configExists) {
        echo json_encode([
            'error' => 'Config file not found',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    require_once $configPath;
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $debug['step'] = 1;
    $debug['received_data'] = $data;
    $debug['employee_id'] = $data['employee_id'] ?? 'missing';
    $debug['password_provided'] = !empty($data['password']);
    $debug['password_length'] = isset($data['password']) ? strlen($data['password']) : 0;
    
    if (empty($data['employee_id']) || empty($data['password'])) {
        echo json_encode([
            'error' => 'Employee ID and password are required',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $debug['step'] = 2;
    $debug['message'] = 'Connecting to database...';
    
    try {
        $db = Database::getInstance()->getConnection();
        $debug['db_connected'] = true;
    } catch (Exception $e) {
        $debug['db_error'] = $e->getMessage();
        echo json_encode([
            'error' => 'Database connection failed: ' . $e->getMessage(),
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $debug['step'] = 3;
    $debug['message'] = 'Searching for user...';
    
    // Ищем пользователя
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.employee_id = ?
    ");
    
    $stmt->execute([$data['employee_id']]);
    $user = $stmt->fetch();
    
    $debug['step'] = 4;
    $debug['user_found'] = $user ? true : false;
    
    if (!$user) {
        // Проверим всех пользователей для отладки
        $stmt = $db->query("SELECT employee_id, full_name, is_active FROM users ORDER BY id LIMIT 10");
        $allUsers = $stmt->fetchAll();
        $debug['available_users'] = $allUsers;
        
        echo json_encode([
            'error' => 'User not found',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $debug['step'] = 5;
    $debug['user_info'] = [
        'id' => $user['id'],
        'employee_id' => $user['employee_id'],
        'full_name' => $user['full_name'],
        'is_active' => $user['is_active'],
        'role_id' => $user['role_id'],
        'role_name' => $user['role_name']
    ];
    $debug['stored_hash'] = substr($user['password_hash'], 0, 30) . '...';
    
    if (!$user['is_active']) {
        echo json_encode([
            'error' => 'User is not active',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $debug['step'] = 6;
    $debug['message'] = 'Verifying password...';
    $debug['password_verify'] = password_verify($data['password'], $user['password_hash']);
    
    if (!password_verify($data['password'], $user['password_hash'])) {
        // Дополнительная отладка пароля
        $debug['password_info'] = [
            'provided_password' => $data['password'],
            'hash_info' => password_get_info($user['password_hash']),
            'test_new_hash' => password_hash($data['password'], PASSWORD_DEFAULT)
        ];
        
        echo json_encode([
            'error' => 'Password mismatch',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $debug['step'] = 7;
    $debug['message'] = 'Login successful!';
    
    // Успешная авторизация
    $sessionId = 'debug_session_' . bin2hex(random_bytes(16));
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'employee_id' => $user['employee_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role_name'],
            'role_display' => $user['role_display_name'],
            'position' => $user['position'],
            'faculty_id' => $user['faculty_id'],
            'department_id' => $user['department_id']
        ],
        'session_id' => $sessionId,
        'debug' => $debug
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    writeErrorLog("Debug login error: " . $e->getMessage());
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => $debug ?? [],
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
?>