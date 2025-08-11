<?php
// debug.php - Файл для диагностики

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Диагностика системы</h1>";

echo "<h2>1. Тест подключения к базе данных</h2>";
try {
    require_once 'config/database.php';
    $db = Database::getInstance();
    echo "✅ Подключение к базе данных: OK<br>";
    
    $connection = $db->getConnection();
    echo "✅ Получение соединения: OK<br>";
    
    $stmt = $connection->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "✅ Тестовый запрос: " . $result['test'] . "<br>";
    
} catch (Exception $e) {
    echo "❌ Ошибка базы данных: " . $e->getMessage() . "<br>";
}

echo "<h2>2. Тест загрузки классов</h2>";
try {
    require_once 'classes/Auth.php';
    echo "✅ Auth.php загружен<br>";
    
    require_once 'classes/AchievementsManager.php';
    echo "✅ AchievementsManager.php загружен<br>";
    
    require_once 'classes/UserManager.php';
    echo "✅ UserManager.php загружен<br>";
    
} catch (Exception $e) {
    echo "❌ Ошибка загрузки классов: " . $e->getMessage() . "<br>";
}

echo "<h2>3. Информация о PHP</h2>";
echo "PHP версия: " . phpversion() . "<br>";
echo "Расширения PDO: " . (extension_loaded('pdo') ? 'Есть' : 'Нет') . "<br>";
echo "Расширения PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'Есть' : 'Нет') . "<br>";

echo "<h2>4. Тест API</h2>";
echo "<a href='api/auth/me'>Тест API endpoint</a><br>";

echo "<h2>5. Проверка прав доступа</h2>";
echo "Права на директорию api/: " . substr(sprintf('%o', fileperms('api/')), -4) . "<br>";
echo "Права на api/index.php: " . substr(sprintf('%o', fileperms('api/index.php')), -4) . "<br>";

if (is_dir('logs')) {
    echo "Директория logs существует<br>";
    echo "Права на logs/: " . substr(sprintf('%o', fileperms('logs/')), -4) . "<br>";
} else {
    echo "Директория logs не существует<br>";
}
?>