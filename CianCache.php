<?php
require_once 'config.php';

class CianCache {
    private $db;

    public function __construct() {
        $db_path = __DIR__ . '/cian_cache.db';
        $this->db = new SQLite3($db_path);
        $this->db->enableExceptions(true);
        $this->initDb();
    }

    private function initDb() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cian_cache (
                cian_id TEXT PRIMARY KEY,
                url TEXT NOT NULL,
                data_json TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Очищаем старые записи (старше 7 дней)
        $this->db->exec("DELETE FROM cian_cache WHERE created_at < datetime('now', '-7 days')");
    }

    public function get($cian_id) {
        $stmt = $this->db->prepare("SELECT * FROM cian_cache WHERE cian_id = :id");
        $stmt->bindValue(':id', $cian_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    public function save($cian_id, $url, $data) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO cian_cache (cian_id, url, data_json, created_at) 
            VALUES (:id, :url, :data, datetime('now'))
        ");
        $stmt->bindValue(':id', $cian_id, SQLITE3_TEXT);
        $stmt->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt->bindValue(':data', json_encode($data, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();
    }
}