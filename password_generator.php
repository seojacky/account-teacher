<?php
// password_generator.php - Генератор хешей паролей для тестирования

$passwords = [
    'admin123',
    'teacher123',
    'dean123',
    'head123'
];

echo "<h2>Генерация хешей паролей:</h2>";

foreach ($passwords as $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "<p><strong>Пароль:</strong> $password<br>";
    echo "<strong>Хеш:</strong> $hash</p>";
    echo "<hr>";
}

// Проверяем существующий хеш
$existing_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
echo "<h3>Проверка существующего хеша:</h3>";
echo "<p>Хеш: $existing_hash<br>";

foreach ($passwords as $password) {
    $verify = password_verify($password, $existing_hash);
    echo "Пароль '$password': " . ($verify ? "✅ СОВПАДАЕТ" : "❌ НЕ СОВПАДАЕТ") . "<br>";
}

// Тест подключения к БД и проверка пользователей
echo "<h3>Проверка пользователей в БД:</h3>";

try {
    if (file_exists('config/database.php')) {
        require_once 'config/database.php';
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT u.employee_id, u.full_name, r.display_name as role, u.is_active,
                   LEFT(u.password_hash, 20) as hash_preview
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            ORDER BY u.role_id
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        if (empty($users)) {
            echo "<p style='color: red;'>❌ Пользователи не найдены! Запустите SQL скрипт выше.</p>";
        } else {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Employee ID</th><th>ПІБ</th><th>Роль</th><th>Активен</th><th>Хеш (preview)</th></tr>";
            
            foreach ($users as $user) {
                $active_color = $user['is_active'] ? 'green' : 'red';
                echo "<tr>";
                echo "<td><strong>{$user['employee_id']}</strong></td>";
                echo "<td>{$user['full_name']}</td>";
                echo "<td>{$user['role']}</td>";
                echo "<td style='color: $active_color;'>" . ($user['is_active'] ? 'ДА' : 'НЕТ') . "</td>";
                echo "<td><code>{$user['hash_preview']}...</code></td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Файл config/database.php не найден</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка БД: " . $e->getMessage() . "</p>";
}

echo "<h3>Логин данные для тестирования:</h3>";
echo "<ul>";
echo "<li><strong>Администратор:</strong> ADMIN / admin123</li>";
echo "<li><strong>Викладач:</strong> TEACHER001 / admin123</li>";
echo "<li><strong>Деканат:</strong> DEAN001 / admin123</li>";
echo "<li><strong>Завідувач:</strong> HEAD001 / admin123</li>";
echo "</ul>";
?>