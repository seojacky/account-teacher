<?php
// config/database.php - Обновленный класс для работы с базой данных

require_once __DIR__ . '/config.php';

if (!class_exists('Database')) {
    class Database {
        private static $instance = null;
        private $connection;
        private $config;
        
        private function __construct() {
            $this->config = Config::getInstance();
            $this->connect();
        }
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Установка соединения с базой данных
         */
        private function connect() {
            $dbConfig = $this->config->getDatabaseConfig();
            
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']}"
            ];
            
            try {
                $this->connection = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
                
                // Логируем успешное подключение в режиме отладки
                if ($this->config->isDebug()) {
                    error_log("Database connection established successfully");
                }
                
            } catch (PDOException $e) {
                $this->logDatabaseError("Database connection failed", $e);
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
                    'host' => $this->config->get('DB_HOST'),
                    'database' => $this->config->get('DB_NAME'),
                    'charset' => $this->config->get('DB_CHARSET')
                ];
            } catch (PDOException $e) {
                $this->logDatabaseError("Failed to get database info", $e);
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
         * Логирование ошибок базы данных
         */
        private function logDatabaseError($message, PDOException $e) {
            $logMessage = sprintf(
                "[%s] %s: %s (Error Code: %s)",
                date('Y-m-d H:i:s'),
                $message,
                $e->getMessage(),
                $e->getCode()
            );
            
            $logFile = $this->config->get('LOG_FILE', '../logs/database.log');
            $logDir = dirname($logFile);
            
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
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
?>