<?php

class Database {
    private $conn;
    
    public function __construct() {
        // Устанавливаем московский часовой пояс для PHP
        date_default_timezone_set('Europe/Moscow');
        $this->connect();
    }
    
    private function connect() {
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "web_ipam";
        
        $this->conn = new mysqli($servername, $username, $password, $dbname);
        
        if ($this->conn->connect_error) {
            error_log("Database connection failed: " . $this->conn->connect_error);
            throw new Exception("Ошибка подключения к базе данных");
        }
        
        // Устанавливаем московский часовой пояс для MySQL
        $this->conn->query("SET time_zone = '+03:00'");
        $this->conn->set_charset("utf8mb4");
    }
    
    public function getConnection() {
        if (!$this->conn || !$this->conn->ping()) {
            $this->connect();
        }
        return $this->conn;
    }
    
    public function safeQuery($sql, $params = [], $types = "") {
        $conn = $this->getConnection();
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        }
        
        return $stmt;
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log($e->getMessage());
    die("Системная ошибка. Пожалуйста, попробуйте позже.");
}
?>