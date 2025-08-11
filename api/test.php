<?php
// api/test.php - Простий тест API

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode([
        'status' => 'API працює!',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s'),
        'request_uri' => $_SERVER['REQUEST_URI'],
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'path_info' => $_SERVER['PATH_INFO'] ?? 'не встановлено',
        'get_params' => $_GET,
        'server_script_name' => $_SERVER['SCRIPT_NAME']
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>