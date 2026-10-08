<?php
// ConversationMemory.php

class ConversationMemory {
    private $pdo;
    private $table = 'conversation_history';
    private $max_messages = 20; // Максимум сообщений для хранения
    private $max_age = 86400; // 24 часа в секундах

    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
            $this->createTable();
        } catch (PDOException $e) {
            error_log("ConversationMemory DB Error: " . $e->getMessage());
        }
    }

    private function createTable() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            role VARCHAR(20) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    /**
     * Добавить сообщение в историю
     */
    public function addMessage($user_id, $role, $content) {
        // Очищаем старые сообщения
        $this->cleanOld($user_id);

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (user_id, role, content) 
            VALUES (?, ?, ?)
        ");

        $result = $stmt->execute([$user_id, $role, $content]);

        // Удаляем самые старые сообщения если превышен лимит
        $this->trimHistory($user_id);

        return $result;
    }

    /**
     * Получить историю сообщений пользователя
     */
    public function getHistory($user_id, $limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT role, content 
            FROM {$this->table} 
            WHERE user_id = ? 
            ORDER BY created_at ASC 
            LIMIT ?
        ");

        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить историю в формате для GPT
     */
    public function getMessagesForGPT($user_id, $system_prompt = null) {
        $history = $this->getHistory($user_id, $this->max_messages);

        $messages = [];

        // Добавляем системный промпт если есть
        if ($system_prompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt
            ];
        }

        // Добавляем историю
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        return $messages;
    }

    /**
     * Очистить старые сообщения пользователя
     */
    private function cleanOld($user_id) {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table} 
            WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$user_id, $this->max_age]);
    }

    /**
     * Удалить самые старые сообщения если превышен лимит
     */
    private function trimHistory($user_id) {
        // Считаем количество сообщений
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM {$this->table} 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $count = $stmt->fetch()['count'];

        // Если превышен лимит, удаляем самые старые
        if ($count > $this->max_messages) {
            $to_delete = $count - $this->max_messages;
            $stmt = $this->pdo->prepare("
                DELETE FROM {$this->table} 
                WHERE user_id = ? 
                ORDER BY created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$user_id, $to_delete]);
        }
    }

    /**
     * Очистить всю историю пользователя
     */
    public function clearHistory($user_id) {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table} 
            WHERE user_id = ?
        ");
        return $stmt->execute([$user_id]);
    }

    /**
     * Получить последние N сообщений
     */
    public function getLastMessages($user_id, $count = 5) {
        $stmt = $this->pdo->prepare("
            SELECT role, content 
            FROM {$this->table} 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");

        $stmt->execute([$user_id, $count]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Получить контекст для поиска
     */
    public function getContextForSearch($user_id) {
        $last_messages = $this->getLastMessages($user_id, 3);

        $context = "Предыдущие сообщения:\n";
        foreach ($last_messages as $msg) {
            $prefix = ($msg['role'] === 'user') ? 'Пользователь' : 'Ассистент';
            $context .= "$prefix: " . $msg['content'] . "\n";
        }

        return $context;
    }
}