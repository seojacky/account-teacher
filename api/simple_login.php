<?php
// api/simple_login.php - Простой API авторизации

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Прямое подключение к БД без классов
    $host = 'localhost';
    $database = 'kalinsky_edebo_system';
    $username = 'kalinsky_edebo_system';
    $password = 'ZVVtQDSmS5N6Y6uHgJqQ';
    
    $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Читаем входные данные
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Отладочная информация
    $debug = [
        'input_raw' => $input,
        'input_decoded' => $data,
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
    ];
    
    if (empty($data['employee_id']) || empty($data['password'])) {
        echo json_encode([
            'error' => 'Employee ID and password are required',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Ищем пользователя
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.employee_id = ? AND u.is_active = 1
    ");
    
    $stmt->execute([$data['employee_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Показываем доступных пользователей для отладки
        $allUsers = $pdo->query("SELECT employee_id, full_name FROM users WHERE is_active = 1")->fetchAll();
        
        echo json_encode([
            'error' => 'User not found',
            'debug' => $debug,
            'available_users' => $allUsers
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Проверяем пароль
    if (!password_verify($data['password'], $user['password_hash'])) {
        echo json_encode([
            'error' => 'Invalid password',
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Успешная авторизация
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
        'session_id' => 'session_' . bin2hex(random_bytes(16))
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => $debug ?? []
    ], JSON_UNESCAPED_UNICODE);
}
?>