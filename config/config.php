<?php
// config/config.php - Система конфигурации приложения

if (!class_exists('Config')) {
    class Config {
        private static $instance = null;
        private $config = [];
        private $envLoaded = false;
        
        private function __construct() {
            $this->loadEnvironment();
            $this->loadDefaultConfig();
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
                'APP_NAME' => 'Кабінет викладача ЄДЕБО',
                'APP_VERSION' => '1.0',
                'SESSION_LIFETIME' => '86400',
                'BCRYPT_ROUNDS' => '12',
                'MAX_FILE_SIZE' => '10485760',
                'ALLOWED_FILE_TYPES' => 'csv,txt',
                'LOG_LEVEL' => 'error',
                'LOG_FILE' => '../logs/app.log'
            ];
            
            foreach ($defaults as $key => $value) {
                if (!isset($this->config[$key])) {
                    $this->config[$key] = $value;
                }
            }
        }
        
        /**
         * Получение значения конфигурации
         */
        public function get($key, $default = null) {
            return $this->config[$key] ?? $default;
        }
        
        /**
         * Получение всей конфигурации
         */
        public function getAll() {
            return $this->config;
        }
        
        /**
         * Получение конфигурации базы данных
         */
        public function getDatabaseConfig() {
            return [
                'host' => $this->get('DB_HOST'),
                'database' => $this->get('DB_NAME'),
                'username' => $this->get('DB_USER'),
                'password' => $this->get('DB_PASS'),
                'charset' => $this->get('DB_CHARSET')
            ];
        }
        
        /**
         * Проверка режима отладки
         */
        public function isDebug() {
            return strtolower($this->get('APP_DEBUG')) === 'true';
        }
        
        /**
         * Проверка загрузки .env файла
         */
        public function isEnvLoaded() {
            return $this->envLoaded;
        }
        
        /**
         * Установка значения конфигурации (только для тестов)
         */
        public function set($key, $value) {
            if ($this->get('APP_ENV') === 'testing') {
                $this->config[$key] = $value;
            } else {
                throw new Exception('Configuration can only be modified in testing environment');
            }
        }
        
        private function __clone() {}
        
        public function __wakeup() {
            throw new Exception("Cannot unserialize singleton");
        }
    }
}
?>