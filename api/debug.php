<?php
// api/debug.php - Діагностика API роутера

header('Content-Type: application/json; charset=utf-8');

try {
    // Показуємо що отримав index.php
    echo json_encode([
        'debug' => 'API роутер',
        'request_uri' => $_SERVER['REQUEST_URI'],
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'script_name' => $_SERVER['SCRIPT_NAME'],
        'path_info' => $_SERVER['PATH_INFO'] ?? null,
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'post_data' => file_get_contents('php://input'),
        'get_params' => $_GET,
        'parsed_path' => explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')),
        'files_exist' => [
            'config.php' => file_exists('../config/config.php'),
            'database.php' => file_exists('../config/database.php'),
            'Auth.php' => file_exists('../classes/Auth.php')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>