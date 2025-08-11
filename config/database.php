<?php
// config/database.php

class Database {
    private static $instance = null;
    private $connection;
    
    private $host = 'localhost';
    private $database = 'edebo_system';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    
    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ];
        
        try {
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Запрет клонирования
    private function __clone() {}
    
    // Запрет сериализации
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// config/config.php

define('APP_ROOT', dirname(__DIR__));
define('BASE_URL', 'http://localhost/edebo-system');
define('API_BASE_URL', BASE_URL . '/api');

// Безопасность
define('JWT_SECRET', 'your-secret-key-change-in-production');
define('JWT_EXPIRE_TIME', 3600 * 8); // 8 часов
define('SESSION_EXPIRE_TIME', 3600 * 24); // 24 часа

// Настройки приложения
define('APP_NAME', 'Кабінет викладача ЄДЕБО');
define('APP_VERSION', '1.0.0');
define('DEFAULT_TIMEZONE', 'Europe/Kiev');

// Установка временной зоны
date_default_timezone_set(DEFAULT_TIMEZONE);

// Настройки загрузки файлов
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', ['csv', 'txt']);

// Настройки пагинации
define('DEFAULT_PAGE_SIZE', 50);
define('MAX_PAGE_SIZE', 100);

// Настройки логирования
define('LOG_FILE', APP_ROOT . '/logs/app.log');
define('ERROR_LOG_FILE', APP_ROOT . '/logs/error.log');

// Функция для логирования
function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
}

// Функция для логирования ошибок
function writeErrorLog($message, $file = '', $line = '') {
    $timestamp = date('Y-m-d H:i:s');
    $location = $file ? " in {$file}" : '';
    $location .= $line ? " on line {$line}" : '';
    $logMessage = "[{$timestamp}] ERROR: {$message}{$location}" . PHP_EOL;
    file_put_contents(ERROR_LOG_FILE, $logMessage, FILE_APPEND | LOCK_EX);
}

// Обработчик ошибок
set_error_handler(function($severity, $message, $file, $line) {
    writeErrorLog($message, $file, $line);
});

// Обработчик исключений
set_exception_handler(function($exception) {
    writeErrorLog($exception->getMessage(), $exception->getFile(), $exception->getLine());
});
?>