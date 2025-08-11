<?php
// config/database.php - Объединенный класс для конфигурации и работы с базой данных

if (!class_exists('Database')) {
    class Database {
        private static $instance = null;
        private $connection;
        private $config = [];
        private $envLoaded = false;
        
        private function __construct() {
            $this->loadEnvironment();
            $this->loadDefaultConfig();
            $this->connect();
        }
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Загрузка переменных окружения из .env файла
         */
        private function loadEnvironment() {
            $envFile = __DIR__ . '/../.env';
            
            if (!file_exists($envFile)) {
                error_log('Warning: .env file not found. Using default configuration.');
                return;
            }
            
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Пропускаем комментарии
                if (strpos($line, '#') === 0) {
                    continue;
                }
                
                // Парсим переменные
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Убираем кавычки если есть
                    if (preg_match('/^(["\']).*\1$/', $value)) {
                        $value = substr($value, 1, -1);
                    }
                    
                    $this->config[$key] = $value;
                    
                    // Устанавливаем в $_ENV если не установлено
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                    }
                }
            }
            
            $this->envLoaded = true;
        }
        
        /**
         * Загрузка конфигурации по умолчанию
         */
        private function loadDefaultConfig() {
            $defaults = [
                'DB_HOST' => 'localhost',
                'DB_NAME' => 'kalinsky_edebo_system',
                'DB_USER' => 'root',
                'DB_PASS' => '',
                'DB_CHARSET' => 'utf8mb4',
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'SESSION_LIFETIME' => '86400',
                'MAX_FILE_SIZE' => '10485760',
                'LOG_LEVEL' => 'error'
            ];
            
            foreach ($defaults as $key => $value) {
                if (!isset($this->config[$key])) {
                    $this->config[$key] = $value;
                }
            }
        }
        
        /**
         * Установка соединения с базой данных
         */
        private function connect() {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->config['DB_HOST'],
                $this->config['DB_NAME'],
                $this->config['DB_CHARSET']
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['DB_CHARSET']}"
            ];
            
            try {
                $this->connection = new PDO($dsn, $this->config['DB_USER'], $this->config['DB_PASS'], $options);
                
                // Логируем успешное подключение в режиме отладки
                if ($this->isDebug()) {
                    $this->writeLog("Database connection established successfully");
                }
                
            } catch (PDOException $e) {
                $this->writeLog("Database connection failed: " . $e->getMessage(), 'error');
                throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
            }
        }
        
        /**
         * Получение соединения с базой данных
         */
        public function getConnection() {
            // Проверяем активность соединения
            if ($this->connection === null) {
                $this->connect();
            }
            
            try {
                $this->connection->query('SELECT 1');
            } catch (PDOException $e) {
                // Переподключаемся если соединение разорвано
                $this->connect();
            }
            
            return $this->connection;
        }
        
        /**
         * Получение значения конфигурации
         */
        public function get($key, $default = null) {
            return $this->config[$key] ?? $default;
        }
        
        /**
         * Проверка режима отладки
         */
        public function isDebug() {
            return strtolower($this->get('APP_DEBUG')) === 'true';
        }
        
        /**
         * Проверка доступности базы данных
         */
        public function isConnected() {
            try {
                return $this->connection && $this->connection->query('SELECT 1') !== false;
            } catch (PDOException $e) {
                return false;
            }
        }
        
        /**
         * Получение информации о базе данных
         */
        public function getDatabaseInfo() {
            try {
                $stmt = $this->connection->query('SELECT VERSION() as version');
                $result = $stmt->fetch();
                
                return [
                    'version' => $result['version'],
                    'host' => $this->get('DB_HOST'),
                    'database' => $this->get('DB_NAME'),
                    'charset' => $this->get('DB_CHARSET')
                ];
            } catch (PDOException $e) {
                $this->writeLog("Failed to get database info: " . $e->getMessage(), 'error');
                return null;
            }
        }
        
        /**
         * Выполнение транзакции
         */
        public function transaction(callable $callback) {
            $this->connection->beginTransaction();
            
            try {
                $result = $callback($this->connection);
                $this->connection->commit();
                return $result;
            } catch (Exception $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }
        
        /**
         * Унифицированное логирование
         */
        public function writeLog($message, $level = 'info') {
            $logMessage = sprintf(
                "[%s] [%s] %s",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $message
            );
            
            $logDir = '../logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . '/app.log';
            error_log($logMessage . "\n", 3, $logFile);
        }
        
        /**
         * Закрытие соединения
         */
        public function disconnect() {
            $this->connection = null;
        }
        
        private function __clone() {}
        
        public function __wakeup() {
            throw new Exception("Cannot unserialize singleton");
        }
        
        /**
         * Деструктор для корректного закрытия соединения
         */
        public function __destruct() {
            $this->disconnect();
        }
    }
}

// Глобальная функция для логирования (для обратной совместимости)
if (!function_exists('writeErrorLog')) {
    function writeErrorLog($message) {
        Database::getInstance()->writeLog($message, 'error');
    }
}
?>