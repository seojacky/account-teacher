<?php
// test_simple_login.php - Тест простого API

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Тест Simple Login API</h2>";

// Тестируем прямое подключение к БД
try {
    $host = 'localhost';
    $database = 'kalinsky_edebo_system';
    $username = 'kalinsky_edebo_system';
    $password = 'ZVVtQDSmS5N6Y6uHgJqQ';
    
    $dsn = "mysql:host=$host;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД успешно<br>";
    
    // Проверяем пользователя ADMIN
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.employee_id = ? AND u.is_active = 1
    ");
    
    $stmt->execute(['ADMIN']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "❌ Пользователь ADMIN не найден<br>";
    } else {
        echo "✅ Пользователь ADMIN найден: {$user['full_name']}<br>";
        
        // Проверяем пароль
        $passwordCheck = password_verify('admin123', $user['password_hash']);
        echo "🔑 Проверка пароля 'admin123': " . ($passwordCheck ? "✅ ВЕРНО" : "❌ НЕВЕРНО") . "<br>";
        
        if ($passwordCheck) {
            echo "<div style='color: green; font-weight: bold; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0;'>";
            echo "🎉 ВСЁ ГОТОВО! Авторизация должна работать.";
            echo "</div>";
            
            echo "<h3>Данные пользователя:</h3>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> {$user['id']}</li>";
            echo "<li><strong>Employee ID:</strong> {$user['employee_id']}</li>";
            echo "<li><strong>ПІБ:</strong> {$user['full_name']}</li>";
            echo "<li><strong>Роль:</strong> {$user['role_display_name']}</li>";
            echo "<li><strong>Email:</strong> " . ($user['email'] ?: 'не указан') . "</li>";
            echo "</ul>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>Инструкции:</h3>";
echo "<ol>";
echo "<li>Создайте файл <code>api/simple_login.php</code> с простым API</li>";
echo "<li>В <code>assets/api.js</code> замените 'debug_login' на 'simple_login'</li>";
echo "<li>Попробуйте войти с данными: <strong>ADMIN</strong> / <strong>admin123</strong></li>";
echo "</ol>";

echo "<p><a href='https://account.kntu.pp.ua/'>← Назад к авторизации</a></p>";
?>