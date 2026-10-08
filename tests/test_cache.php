<?php
require_once 'config.php';
require_once 'Cache.php';

echo "🚀 Тестирование системы кэширования\n";
echo str_repeat("=", 50) . "\n";

try {
    // 1. Создаем экземпляр кэша
    echo "1. Подключение к базе данных... ";
    $cache = new EstimateCache();
    echo "✅ OK\n";

    // 2. Тестовые параметры квартиры
    echo "2. Создание тестовых данных... ";
    $params = [
        'city' => 'Москва',
        'district' => 'Хамовники',
        'complex' => 'Barkli Plaza',
        'area' => 175,
        'rooms' => 3,
        'floor' => 4,
        'renovation' => 'дизайнерский'
    ];

    $query_text = "ЖК Barkli Plaza, Москва, Хамовники, 175м², 3 комнаты, 4 этаж, дизайнерский ремонт";
    echo "✅ OK\n";

    // 3. Создаем хеш
    echo "3. Создание хеша запроса... ";
    $hash = $cache->createHash($params);
    echo "✅ OK (hash: $hash)\n";

    // 4. Проверяем, есть ли уже в кэше
    echo "4. Проверка наличия в кэше... ";
    $existing = $cache->get($hash);
    if ($existing) {
        echo "✅ Найдено в кэше!\n";
        echo "   ID: " . $existing['id'] . "\n";
        echo "   Дата: " . $existing['created_at'] . "\n";
        echo "   Ответ: " . json_encode($existing['response_json'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "❌ Не найдено\n";

        // 5. Создаем тестовый ответ
        echo "5. Создание тестового ответа GPT... ";
        $test_response = [
            'min_price' => 85000000,
            'max_price' => 105000000,
            'avg_price' => 95000000,
            'price_per_m2' => 542857,
            'confidence' => 'high',
            'reasoning' => 'Квартира расположена в элитном ЖК Barkli Plaza в Хамовниках. Дизайнерский ремонт высокого качества. Средняя стоимость в данном комплексе 500-600 тыс руб/м².'
        ];
        echo "✅ OK\n";

        // 6. Сохраняем в кэш
        echo "6. Сохранение в кэш... ";
        $cache->save($hash, $query_text, $params, $test_response);
        echo "✅ OK\n";

        // 7. Проверяем, что сохранилось
        echo "7. Проверка сохранения... ";
        $saved = $cache->get($hash);
        if ($saved) {
            echo "✅ OK\n";
            echo "   ID: " . $saved['id'] . "\n";
            echo "   Город: " . $saved['city'] . "\n";
            echo "   Район: " . $saved['district'] . "\n";
            echo "   ЖК: " . $saved['complex'] . "\n";
            echo "   Цена мин: " . number_format($saved['price_min'], 0, '.', ' ') . " ₽\n";
            echo "   Цена макс: " . number_format($saved['price_max'], 0, '.', ' ') . " ₽\n";
            echo "   Цена сред: " . number_format($saved['price_avg'], 0, '.', ' ') . " ₽\n";
        } else {
            echo "❌ Ошибка сохранения\n";
        }
    }

    // 8. Тест поиска похожих
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "8. Поиск похожих запросов по району Хамовники...\n";
    $similar = $cache->findSimilar(['district' => 'Хамовники'], 3);

    if (!empty($similar)) {
        foreach ($similar as $idx => $s) {
            echo "   {$idx}. ID: {$s['id']}, Город: {$s['city']}, ЖК: {$s['complex']}\n";
            echo "      Цена: " . number_format($s['price_avg'], 0, '.', ' ') . " ₽\n";
        }
    } else {
        echo "   Похожих запросов не найдено\n";
    }

    // 9. Статистика
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "9. Статистика кэша:\n";
    $stats = $cache->getStats();
    echo "   Всего записей: {$stats['total']}\n";
    echo "   За 24 часа: {$stats['last_24h']}\n";
    echo "   По городам:\n";
    foreach ($stats['by_city'] as $city) {
        echo "      - {$city['city']}: {$city['count']}\n";
    }

} catch (Exception $e) {
    echo "\n❌ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Тестирование завершено\n";