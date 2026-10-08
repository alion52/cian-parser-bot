<?php
require_once 'config.php';

class UserMode {
    private $pdo;
    private $table = 'user_modes';

    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
        } catch (PDOException $e) {
            error_log("UserMode DB Error: " . $e->getMessage());
        }
    }

    public function getMode($user_id) {
        $stmt = $this->pdo->prepare("SELECT mode FROM {$this->table} WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result ? $result['mode'] : 'general'; // По умолчанию общий режим
    }

    public function setMode($user_id, $mode) {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (user_id, mode) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE mode = ?, updated_at = NOW()
        ");
        return $stmt->execute([$user_id, $mode, $mode]);
    }

    public function saveLastQuery($user_id, $query) {
        $stmt = $this->pdo->prepare("
            UPDATE {$this->table} SET last_query = ? WHERE user_id = ?
        ");
        return $stmt->execute([$query, $user_id]);
    }

    public function getLastQuery($user_id) {
        $stmt = $this->pdo->prepare("SELECT last_query FROM {$this->table} WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result ? $result['last_query'] : '';
    }
}