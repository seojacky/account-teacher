<?php
// config/database.php

if (!class_exists('Database')) {
    class Database {
        private static $instance = null;
        private $connection;
        
        private $host = 'localhost';
        private $database = 'kalinsky_edebo_system';
        private $username = 'kalinsky_edebo_system';
        private $password = 'ZVVtQDSmS5N6Y6uHgJqQ';
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
        
        private function __clone() {}
        
        public function __wakeup() {
            throw new Exception("Cannot unserialize singleton");
        }
    }
}
?>