<?php
// config/config.php - Загальні налаштування системи

/**
 * Конфігурація системи ЄДЕБО
 */
class Config {
    
    /**
     * Налаштування сесій
     */
    const SESSION_EXPIRE_TIME = 86400; // 24 години в секундах
    const SESSION_CLEANUP_INTERVAL = 3600; // Очищення кожну годину
    
    /**
     * Налаштування файлів
     */
    const MAX_UPLOAD_SIZE = 10485760; // 10MB в байтах
    const ALLOWED_FILE_TYPES = ['csv', 'txt'];
    const UPLOAD_DIR = '../uploads/';
    
    /**
     * Налаштування пагінації
     */
    const DEFAULT_PAGE_SIZE = 50;
    const MAX_PAGE_SIZE = 200;
    
    /**
     * Налаштування логування
     */
    const LOG_LEVEL = 'error'; // debug, info, warning, error
    const LOG_DIR = '../logs/';
    const LOG_MAX_FILES = 30; // Максимум файлів логів
    const LOG_MAX_SIZE = 52428800; // 50MB максимальний розмір файлу логу
    
    /**
     * Налаштування безпеки
     */
    const BCRYPT_ROUNDS = 12;
    const PASSWORD_MIN_LENGTH = 6;
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 хвилин
    
    /**
     * Налаштування API
     */
    const API_RATE_LIMIT = 1000; // Запитів на годину на користувача
    const API_TIMEOUT = 30; // Секунд
    
    /**
     * Налаштування CSV експорту
     */
    const CSV_DEFAULT_ENCODING = 'utf8bom';
    const CSV_SEPARATOR = ';';
    const CSV_ENCLOSURE = '"';
    const CSV_ESCAPE = '"';
    
    /**
     * Налаштування системи
     */
    const APP_NAME = 'Кабінет викладача ЄДЕБО';
    const TIMEZONE = 'Europe/Kiev';
    
    /**
     * Налаштування досягнень
     */
    const ACHIEVEMENTS_COUNT = 20;
    const AUTOSAVE_INTERVAL = 30; // Секунд
    const ACHIEVEMENTS_MAX_LENGTH = 5000; // Символів на одне досягнення
    
    /**
     * Отримання версії системи з README.md
     * @return string
     */
    public static function getVersion() {
        static $version = null;
        
        if ($version === null) {
            try {
                $readmePath = __DIR__ . '/../README.md';
                
                if (file_exists($readmePath)) {
                    $readmeContent = file_get_contents($readmePath);
                    
                    // Шукаємо версію в заголовку типу "# Кабінет викладача ЄДЕБО v1.8.0"
                    if (preg_match('/^#\s+.*\s+v(\d+\.\d+\.\d+)/m', $readmeContent, $matches)) {
                        $version = $matches[1];
                    } else {
                        $version = '1.0.0'; // За замовчуванням
                    }
                } else {
                    $version = '1.0.0'; // Якщо README.md не знайдено
                }
            } catch (Exception $e) {
                $version = '1.0.0'; // При помилці
            }
        }
        
        return $version;
    }
    
    /**
     * Отримання повної інформації про версію
     * @return array
     */
    public static function getVersionInfo() {
        return [
            'version' => self::getVersion(),
            'name' => self::APP_NAME,
            'full_name' => self::APP_NAME . ' v' . self::getVersion(),
            'build_date' => self::getBuildDate(),
            'environment' => self::getEnvironment()
        ];
    }
    
    /**
     * Отримання дати збірки (з README.md changelog)
     * @return string|null
     */
    public static function getBuildDate() {
        static $buildDate = null;
        
        if ($buildDate === null) {
            try {
                $readmePath = __DIR__ . '/../README.md';
                
                if (file_exists($readmePath)) {
                    $readmeContent = file_get_contents($readmePath);
                    $currentVersion = self::getVersion();
                    
                    // Шукаємо дату для поточної версії в changelog
                    $pattern = '/###\s+v' . preg_quote($currentVersion, '/') . '\s+\(([0-9-]+)\)/';
                    if (preg_match($pattern, $readmeContent, $matches)) {
                        $buildDate = $matches[1];
                    }
                }
                
                if ($buildDate === null) {
                    $buildDate = date('Y-m-d'); // Поточна дата як fallback
                }
            } catch (Exception $e) {
                $buildDate = date('Y-m-d');
            }
        }
        
        return $buildDate;
    }
    
    /**
     * Отримання налаштування за ключем
     * @param string $key Ключ налаштування
     * @param mixed $default Значення за замовчуванням
     * @return mixed
     */
    public static function get($key, $default = null) {
        // Спеціальна обробка для VERSION
        if ($key === 'APP_VERSION' || $key === 'VERSION') {
            return self::getVersion();
        }
        
        if (defined("self::$key")) {
            return constant("self::$key");
        }
        
        // Спробуємо знайти в змінних середовища
        $envValue = $_ENV[$key] ?? null;
        if ($envValue !== null) {
            return $envValue;
        }
        
        return $default;
    }
    
    /**
     * Перевірка чи увімкнено режим відладки
     * @return bool
     */
    public static function isDebug() {
        $debug = $_ENV['APP_DEBUG'] ?? 'false';
        return strtolower($debug) === 'true';
    }
    
    /**
     * Отримання середовища виконання
     * @return string
     */
    public static function getEnvironment() {
        return $_ENV['APP_ENV'] ?? 'production';
    }
    
    /**
     * Перевірка дозволеного типу файлу
     * @param string $filename Ім'я файлу
     * @return bool
     */
    public static function isAllowedFileType($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_FILE_TYPES);
    }
    
    /**
     * Перевірка розміру файлу
     * @param int $fileSize Розмір файлу в байтах
     * @return bool
     */
    public static function isAllowedFileSize($fileSize) {
        return $fileSize <= self::MAX_UPLOAD_SIZE;
    }
    
    /**
     * Отримання максимального розміру файлу у читабельному форматі
     * @return string
     */
    public static function getMaxUploadSizeFormatted() {
        return self::formatFileSize(self::MAX_UPLOAD_SIZE);
    }
    
    /**
     * Налаштування часової зони
     */
    public static function setTimezone() {
        date_default_timezone_set(self::TIMEZONE);
    }
    
    /**
     * Ініціалізація конфігурації
     */
    public static function init() {
        self::setTimezone();
        
        // Створюємо необхідні директорії
        self::createDirectories();
    }
    
    /**
     * Створення необхідних директорій
     */
    private static function createDirectories() {
        $directories = [
            self::LOG_DIR,
            self::UPLOAD_DIR
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}

// Ініціалізуємо конфігурацію при підключенні файлу
Config::init();
?>