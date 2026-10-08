<?php
// WebCache.php - Кэш для результатов веб-поиска

class WebCache {
    private $cache_dir = 'web_cache/';
    private $cache_time = 3600; // 1 час по умолчанию

    public function __construct($cache_time = 3600) {
        $this->cache_time = $cache_time;

        // Создаем директорию для кэша, если её нет
        if (!file_exists($this->cache_dir)) {
            if (!mkdir($this->cache_dir, 0777, true)) {
                error_log("WebCache: Failed to create cache directory");
            }
        }
    }

    /**
     * Получить данные из кэша
     */
    public function get($query) {
        $hash = md5($query);
        $file = $this->cache_dir . $hash . '.json';

        if (file_exists($file)) {
            // Проверяем время жизни кэша
            if ((time() - filemtime($file)) < $this->cache_time) {
                $data = json_decode(file_get_contents($file), true);

                if (is_array($data) && isset($data['data'])) {
                    error_log("WebCache: Hit for query: " . substr($query, 0, 50) . "...");
                    return $data['data'];
                }
            } else {
                // Удаляем устаревший кэш
                unlink($file);
                error_log("WebCache: Expired cache deleted");
            }
        }

        error_log("WebCache: Miss for query: " . substr($query, 0, 50) . "...");
        return null;
    }

    /**
     * Сохранить данные в кэш
     */
    public function save($query, $data) {
        $hash = md5($query);
        $file = $this->cache_dir . $hash . '.json';

        $cache_data = [
            'data' => $data,
            'timestamp' => time(),
            'query' => $query
        ];

        if (file_put_contents($file, json_encode($cache_data, JSON_UNESCAPED_UNICODE))) {
            error_log("WebCache: Saved for query: " . substr($query, 0, 50) . "...");
            return true;
        }

        error_log("WebCache: Failed to save");
        return false;
    }

    /**
     * Очистить весь кэш
     */
    public function clear() {
        $files = glob($this->cache_dir . '*.json');
        $count = 0;

        foreach ($files as $file) {
            if (unlink($file)) $count++;
        }

        error_log("WebCache: Cleared {$count} files");
        return $count;
    }

    /**
     * Очистить устаревший кэш
     */
    public function clean() {
        $files = glob($this->cache_dir . '*.json');
        $count = 0;

        foreach ($files as $file) {
            if ((time() - filemtime($file)) > $this->cache_time) {
                if (unlink($file)) $count++;
            }
        }

        if ($count > 0) {
            error_log("WebCache: Cleaned {$count} expired files");
        }

        return $count;
    }
}