<?php
// test_debug.php - Прямой тест отладочного API

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Тест отладочного API</h2>";

// Имитируем POST запрос
$testData = json_encode([
    'employee_id' => 'ADMIN',
    'password' => 'admin123'
]);

// Устанавливаем переменные окружения
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/debug_login';

// Временно перенаправляем input
$handle = fopen('php://temp', 'r+');
fwrite($handle, $testData);
rewind($handle);

echo "<h3>Входные данные:</h3>";
echo "<pre>" . htmlspecialchars($testData) . "</pre>";

echo "<h3>Результат debug_login.php:</h3>";

// Захватываем вывод
ob_start();

try {
    // Перенаправляем stdin
    $originalInput = 'php://input';
    
    // Включаем файл
    include 'api/debug_login.php';
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}

$output = ob_get_clean();

echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>";
echo htmlspecialchars($output);
echo "</pre>";

// Декодируем JSON для красивого отображения
$decoded = json_decode($output, true);
if ($decoded) {
    echo "<h3>Декодированный ответ:</h3>";
    echo "<pre style='background: #e8f5e8; padding: 10px; border-radius: 5px;'>";
    print_r($decoded);
    echo "</pre>";
    
    if (isset($decoded['success']) && $decoded['success']) {
        echo "<div style='color: green; font-weight: bold; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0;'>";
        echo "✅ АВТОРИЗАЦИЯ УСПЕШНА!";
        echo "</div>";
    } else {
        echo "<div style='color: red; font-weight: bold; padding: 10px; background: #f8d7da; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ ОШИБКА АВТОРИЗАЦИИ: " . ($decoded['error'] ?? 'Неизвестная ошибка');
        echo "</div>";
    }
}

fclose($handle);
?>

<hr>
<h3>Инструкции:</h3>
<ol>
    <li>Если видите "✅ АВТОРИЗАЦИЯ УСПЕШНА" - попробуйте войти через браузер</li>
    <li>Если ошибка - смотрите детали в "debug" секции</li>
    <li>Проверьте что файл api/debug_login.php загружен корректно</li>
</ol>

<p><a href="https://account.kntu.pp.ua/">← Назад к авторизации</a></p>