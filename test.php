<?php
// test.php - Тестовий файл для діагностики

echo "PHP працює!<br>";
echo "Версія PHP: " . PHP_VERSION . "<br>";
echo "Поточна папка: " . __DIR__ . "<br>";

// Перевіряємо чи існують файли
$files_to_check = [
    'config/config.php',
    'config/database.php', 
    'classes/Auth.php',
    'api/index.php'
];

echo "<h3>Перевірка файлів:</h3>";
foreach ($files_to_check as $file) {
    $exists = file_exists($file) ? 'ІСНУЄ' : 'НЕ ІСНУЄ';
    echo "$file - $exists<br>";
}

// Тестуємо підключення до БД
echo "<h3>Тест підключення до БД:</h3>";
try {
    if (file_exists('config/database.php')) {
        require_once 'config/database.php';
        $db = Database::getInstance()->getConnection();
        echo "Підключення до БД: УСПІШНО<br>";
        
        // Тестуємо запит
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
        $stmt->execute();
        $result = $stmt->fetch();
        echo "Користувачів у БД: " . $result['count'] . "<br>";
    } else {
        echo "Файл config/database.php не знайдено<br>";
    }
} catch (Exception $e) {
    echo "Помилка БД: " . $e->getMessage() . "<br>";
}

// Тестуємо require файлів
echo "<h3>Тест завантаження класів:</h3>";
try {
    if (file_exists('config/config.php')) {
        require_once 'config/config.php';
        echo "config.php - ЗАВАНТАЖЕНО<br>";
    }
    
    if (file_exists('classes/Auth.php')) {
        require_once 'classes/Auth.php';
        echo "Auth.php - ЗАВАНТАЖЕНО<br>";
    }
} catch (Exception $e) {
    echo "Помилка завантаження: " . $e->getMessage() . "<br>";
}

phpinfo();
?>