<?php
require_once 'config.php';

class EstimateCache {
    private $pdo;
    private $table = 'estimate_cache';

    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

            $this->pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                DB_OPTIONS
            );

        } catch (PDOException $e) {
            error_log("Cache DB Error: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Создает хеш из параметров запроса
     */
    public function createHash($params) {
        // Нормализуем параметры (убираем лишние пробелы, приводим к нижнему регистру)
        $normalized = array_map(function($value) {
            if (is_string($value)) {
                return trim(mb_strtolower($value));
            }
            if (is_numeric($value)) {
                return (float)$value;
            }
            return $value;
        }, $params);

        // Сортируем ключи для консистентности
        ksort($normalized);

        return md5(json_encode($normalized));
    }

    /**
     * Проверяет, есть ли запрос в кэше
     */
    public function get($hash) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table} 
            WHERE query_hash = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$hash]);
        $result = $stmt->fetch();

        if ($result) {
            // Декодируем JSON
            $result['params_json'] = json_decode($result['params_json'], true);
            $result['response_json'] = json_decode($result['response_json'], true);
        }

        return $result;
    }

    /**
     * Сохраняет результат в кэш
     */
    public function save($hash, $query_text, $params, $response) {
        // Извлекаем основные параметры для индексации
        $city = $params['city'] ?? null;
        $district = $params['district'] ?? null;
        $complex = $params['complex'] ?? null;

        // Извлекаем цены из ответа
        $price_min = $response['min_price'] ?? null;
        $price_max = $response['max_price'] ?? null;
        $price_avg = $response['avg_price'] ?? null;

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} 
            (query_hash, query_text, params_json, response_json, city, district, complex, price_min, price_max, price_avg) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $hash,
            $query_text,
            json_encode($params, JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $city,
            $district,
            $complex,
            $price_min,
            $price_max,
            $price_avg
        ]);
    }

    /**
     * Ищет похожие запросы по городу и району
     */
    public function findSimilar($params, $limit = 5) {
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $conditions = [];
        $values = [];

        if (!empty($params['city'])) {
            $conditions[] = "city = ?";
            $values[] = $params['city'];
        }

        if (!empty($params['district'])) {
            $conditions[] = "district = ?";
            $values[] = $params['district'];
        }

        if (!empty($params['complex'])) {
            $conditions[] = "complex LIKE ?";
            $values[] = '%' . $params['complex'] . '%';
        }

        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY created_at DESC LIMIT " . intval($limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        $results = $stmt->fetchAll();

        // Декодируем JSON для каждого результата
        foreach ($results as &$result) {
            $result['params_json'] = json_decode($result['params_json'], true);
            $result['response_json'] = json_decode($result['response_json'], true);
        }

        return $results;
    }

    /**
     * Очищает старые записи (старше N дней)
     */
    public function cleanOld($days = 30) {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table} 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        return $stmt->execute([$days]);
    }

    /**
     * Получает статистику по кэшу
     */
    public function getStats() {
        $stats = [];

        // Общее количество
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM {$this->table}");
        $stats['total'] = $stmt->fetch()['total'];

        // По городам
        $stmt = $this->pdo->query("
            SELECT city, COUNT(*) as count 
            FROM {$this->table} 
            WHERE city IS NOT NULL 
            GROUP BY city 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['by_city'] = $stmt->fetchAll();

        // По районам
        $stmt = $this->pdo->query("
            SELECT district, COUNT(*) as count 
            FROM {$this->table} 
            WHERE district IS NOT NULL 
            GROUP BY district 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['by_district'] = $stmt->fetchAll();

        // По ЖК
        $stmt = $this->pdo->query("
            SELECT complex, COUNT(*) as count 
            FROM {$this->table} 
            WHERE complex IS NOT NULL 
            GROUP BY complex 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['by_complex'] = $stmt->fetchAll();

        // За последние 24 часа
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as count 
            FROM {$this->table} 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stats['last_24h'] = $stmt->fetch()['count'];

        // Средняя цена по всем
        $stmt = $this->pdo->query("
            SELECT 
                AVG(price_avg) as avg_price_all,
                MIN(price_min) as min_price_all,
                MAX(price_max) as max_price_all
            FROM {$this->table}
        ");
        $stats['prices'] = $stmt->fetch();

        return $stats;
    }

    /**
     * Получает средние цены по городу
     */
    public function getAverageByCity($city) {
        $stmt = $this->pdo->prepare("
            SELECT 
                AVG(price_avg) as avg_price,
                AVG(price_per_m2) as avg_price_per_m2
            FROM (
                SELECT 
                    price_avg,
                    (price_avg / JSON_UNQUOTE(JSON_EXTRACT(params_json, '$.area'))) as price_per_m2
                FROM {$this->table} 
                WHERE city = ? 
                AND price_avg IS NOT NULL
                ORDER BY created_at DESC 
                LIMIT 100
            ) as recent
        ");
        $stmt->execute([$city]);
        return $stmt->fetch();
    }
}