<?php
// test_api.php - Тест API авторизации

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Тест API авторизации</h2>";

// Имитируем POST запрос к API
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/auth/login';

// Устанавливаем тестовые данные
$testData = [
    'employee_id' => 'ADMIN',
    'password' => 'admin123'
];

// Имитируем входные данные
ob_start();
echo json_encode($testData);
$inputData = ob_get_clean();

// Временно перенаправляем input
$tempInput = tmpfile();
fwrite($tempInput, $inputData);
rewind($tempInput);

try {
    echo "<h3>Тестируем подключение к БД:</h3>";
    
    if (!file_exists('config/database.php')) {
        echo "❌ Файл config/database.php не найден<br>";
        exit;
    }
    
    require_once 'config/database.php';
    $db = Database::getInstance()->getConnection();
    echo "✅ Подключение к БД успешно<br>";
    
    echo "<h3>Проверяем пользователей:</h3>";
    $stmt = $db->query("SELECT employee_id, full_name, is_active FROM users");
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "❌ Пользователи не найдены!<br>";
        echo "<p>Выполните SQL скрипт для создания пользователей.</p>";
        exit;
    }
    
    echo "<table border='1'>";
    echo "<tr><th>Employee ID</th><th>ПІБ</th><th>Активен</th></tr>";
    foreach ($users as $user) {
        $color = $user['is_active'] ? 'green' : 'red';
        echo "<tr>";
        echo "<td><strong>{$user['employee_id']}</strong></td>";
        echo "<td>{$user['full_name']}</td>";
        echo "<td style='color: $color;'>" . ($user['is_active'] ? 'ДА' : 'НЕТ') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Тестируем авторизацию:</h3>";
    
    // Ищем админа
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.display_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.employee_id = ? AND u.is_active = 1
    ");
    
    $stmt->execute(['ADMIN']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "❌ Пользователь ADMIN не найден или неактивен<br>";
        exit;
    }
    
    echo "✅ Пользователь ADMIN найден: {$user['full_name']}<br>";
    echo "📋 Роль: {$user['display_name']}<br>";
    echo "🔐 Хеш пароля: " . substr($user['password_hash'], 0, 30) . "...<br>";
    
    // Проверяем пароль
    $passwordCheck = password_verify('admin123', $user['password_hash']);
    echo "🔑 Проверка пароля 'admin123': " . ($passwordCheck ? "✅ ВЕРНО" : "❌ НЕВЕРНО") . "<br>";
    
    if (!$passwordCheck) {
        echo "<h4>Исправление пароля:</h4>";
        $newHash = password_hash('admin123', PASSWORD_DEFAULT);
        echo "Новый хеш для 'admin123': <code>$newHash</code><br>";
        
        // Обновляем пароль
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE employee_id = 'ADMIN'");
        $updateStmt->execute([$newHash]);
        echo "✅ Пароль обновлен в БД<br>";
        
        // Проверяем снова
        $newCheck = password_verify('admin123', $newHash);
        echo "🔑 Повторная проверка: " . ($newCheck ? "✅ ВЕРНО" : "❌ НЕВЕРНО") . "<br>";
    }
    
    echo "<h3>Тест прямого API вызова:</h3>";
    
    // Включаем API
    $_POST = $testData;
    
    ob_start();
    include 'api/index.php';
    $apiResponse = ob_get_clean();
    
    echo "<h4>Ответ API:</h4>";
    echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>";
    echo htmlspecialchars($apiResponse);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<h3>Рекомендации:</h3>";
echo "<ol>";
echo "<li>Убедитесь, что пользователь ADMIN создан и активен</li>";
echo "<li>Проверьте правильность хеша пароля</li>";
echo "<li>Используйте данные: <strong>ADMIN</strong> / <strong>admin123</strong></li>";
echo "<li>Если проблема остается, создайте api/debug_login.php</li>";
echo "</ol>";
?>