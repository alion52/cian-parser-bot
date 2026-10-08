<?php
require_once 'config.php';
require_once 'Cache.php';
require_once 'UserMode.php';
require_once 'parser.php';
require_once 'gpt_client.php';
require_once 'pdf_generator.php';
require_once 'WebCache.php';
require_once 'ConversationMemory.php';

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (!file_exists('logs')) {
    mkdir('logs', 0777, true);
}

function debug_log($message) {
    $log_file = 'logs/bot_debug.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$time] $message\n", FILE_APPEND);
}

// Функция для сохранения сообщений в админку Laravel
function saveMessageToLaravel($userId, $role, $content) {
    // Не сохраняем пустые сообщения
    if (empty($content)) {
        return false;
    }

    // ВАЖНО: используем поддомен admin!
    $url = 'http://admin.example.com/api/messages';

    $data = [
        'user_id' => (int)$userId,
        'role' => $role,
        'content' => $content,
        'metadata' => [
            'source' => 'telegram',
            'timestamp' => time()
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_error($ch)) {
        debug_log("Laravel API CURL Error: " . curl_error($ch));
    } elseif ($http_code != 200 && $http_code != 201) {
        debug_log("Laravel API HTTP Error: $http_code, Response: $result");
    }

    curl_close($ch);
    return $http_code == 200 || $http_code == 201;
}

http_response_code(200);

$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Обработка callback_query (нажатие на инлайн кнопки)
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $chat_id = $callback["message"]["chat"]["id"];
    $user_id = $callback["from"]["id"];
    $data = $callback["data"];

    // Сохраняем callback как сообщение пользователя
    saveMessageToLaravel($user_id, 'user', "[callback] " . $data);

    $userMode = new UserMode();

    if ($data === 'mode_expert') {
        $userMode->setMode($user_id, 'expert');
        sendMessage($chat_id, "✅ Режим экспертной оценки активирован");
    } elseif ($data === 'mode_general') {
        $userMode->setMode($user_id, 'general');
        sendMessage($chat_id, "✅ Режим общих вопросов активирован");
    } elseif ($data === 'menu') {
        showMainMenu($chat_id);
        $userMode->setMode($user_id, 'menu');
    } elseif ($data === 'web_search') {
        $userMode->setMode($user_id, 'general');
        sendMessage($chat_id, "🔍 Введите ваш запрос для поиска в интернете:");
    } elseif ($data === 'clear_history') {
        $memory = new ConversationMemory();
        $memory->clearHistory($user_id);
        sendMessage($chat_id, "🧹 История диалога очищена");
    } elseif ($data === 'my_queries') {
        sendMessage($chat_id, "📊 Функция истории запросов в разработке");
    } elseif ($data === 'help') {
        sendHelp($chat_id);
    } elseif (strpos($data, 'expert_') === 0) {
        handleExpertCallback($user_id, $chat_id, $data);
    }

    http_response_code(200);
    exit();
}

// Обработка обычных сообщений
if (!$update || !isset($update["message"])) {
    exit();
}

$chat_id = $update["message"]["chat"]["id"];
$text = $update["message"]["text"] ?? '';
$user_id = $update["message"]["from"]["id"];

debug_log("Получено сообщение от $user_id: $text");

// Сохраняем сообщение пользователя в админку
saveMessageToLaravel($user_id, 'user', $text);

$userMode = new UserMode();

if ($text === '/start') {
    showMainMenu($chat_id);
    $userMode->setMode($user_id, 'menu');
    exit();
}

if ($text === '/menu' || $text === '🏠 Главное меню') {
    showMainMenu($chat_id);
    $userMode->setMode($user_id, 'menu');
    exit();
}

if ($text === '/clear' || $text === '🧹 Очистить историю') {
    $memory = new ConversationMemory();
    $memory->clearHistory($user_id);
    sendMessage($chat_id, "🧹 История диалога очищена");
    exit();
}

$current_mode = $userMode->getMode($user_id);

if ($current_mode === 'menu') {
    handleMenuSelection($user_id, $chat_id, $text, $userMode);
} elseif ($current_mode === 'expert') {
    handleExpertMode($user_id, $chat_id, $text, $userMode);
} else {
    handleGeneralMode($chat_id, $text);
}

function sendHelp($chat_id) {
    $help_text = "❓ *Помощь по использованию*\n\n"
        . "• /menu — открыть главное меню\n"
        . "• /clear — очистить историю диалога\n\n"
        . "*Режимы работы:*\n"
        . "🏢 *Экспертная оценка* — подробный анализ стоимости недвижимости с PDF отчетом\n"
        . "💬 *Общие вопросы* — чат с поддержкой диалога и поиском в интернете\n"
        . "🔍 *Поиск в интернете* — быстрый поиск информации\n\n"
        . "📞 По вопросам: @support";

    sendMessage($chat_id, $help_text);
}

function handleExpertCallback($user_id, $chat_id, $data) {
    $parts = explode('_', $data);
    $type = $parts[1] ?? '';
    $value = $parts[2] ?? '';

    $memory = new ConversationMemory();
    $history = $memory->getLastMessages($user_id, 20);

    $last_assessment = getLastAssessmentFromHistory($history);

    if (!$last_assessment) {
        sendMessage($chat_id, "❌ Не найдена предыдущая оценка. Начните новую.");
        handleExpertMode($user_id, $chat_id, "", $userMode = new UserMode());
        return;
    }

    $refined_query = $last_assessment['original_query'];

    switch ($type) {
        case 'district':
            $district = $value === 'center' ? 'центр' : 'спальный район';
            $refined_query .= ", район: $district";
            break;
        case 'area':
            switch ($value) {
                case 'small': $refined_query .= ", площадь 35 м²"; break;
                case 'medium': $refined_query .= ", площадь 60 м²"; break;
                case 'large': $refined_query .= ", площадь 90 м²"; break;
            }
            break;
        case 'rooms':
            $refined_query .= ", $value комнаты";
            break;
        case 'repair':
            switch ($value) {
                case 'no': $refined_query .= ", без отделки"; break;
                case 'cosmetic': $refined_query .= ", косметический ремонт"; break;
                case 'euro': $refined_query .= ", евроремонт"; break;
            }
            break;
    }

    handleExpertMode($user_id, $chat_id, $refined_query, new UserMode());
}

function showMainMenu($chat_id) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🏢 Экспертная оценка', 'callback_data' => 'mode_expert'],
                ['text' => '💬 Общие вопросы', 'callback_data' => 'mode_general']
            ],
            [
                ['text' => '🧹 Очистить историю', 'callback_data' => 'clear_history'],
                ['text' => '🔍 Поиск в интернете', 'callback_data' => 'web_search']
            ],
            [
                ['text' => '📊 Мои запросы', 'callback_data' => 'my_queries'],
                ['text' => '❓ Помощь', 'callback_data' => 'help']
            ]
        ]
    ];

    sendMessageWithKeyboard($chat_id,
        "👋 *Добро пожаловать!*\n\nВыберите режим работы:\n\n" .
        "🏢 *Экспертная оценка* — подробный анализ стоимости недвижимости с PDF отчетом\n" .
        "💬 *Общие вопросы* — GPT чат с поддержкой диалога и поиском в интернете\n" .
        "🔍 *Поиск в интернете* — быстрый поиск информации\n" .
        "🧹 *Очистить историю* — сброс контекста диалога\n" .
        "📊 *Мои запросы* — история ваших оценок\n" .
        "❓ *Помощь* — инструкция по использованию",
        $keyboard
    );
}

function handleMenuSelection($user_id, $chat_id, $text, $userMode) {
    if ($text === '🏢 Экспертная оценка') {
        $userMode->setMode($user_id, 'expert');
        sendMessage($chat_id,
            "✅ *Режим экспертной оценки активирован*\n\n" .
            "Пришлите описание квартиры, например:\n" .
            "`ЖК Счастье на Пресне, 69м², 11 этаж, под отделку, 2 комнаты`\n\n" .
            "Я подготовлю подробный PDF отчет с анализом."
        );
    } elseif ($text === '💬 Общие вопросы') {
        $userMode->setMode($user_id, 'general');
        sendMessage($chat_id,
            "✅ *Режим общих вопросов активирован*\n\n" .
            "Задавайте любые вопросы, я буду искать актуальную информацию в интернете и отвечать."
        );
    } elseif ($text === '📊 Мои запросы') {
        sendMessage($chat_id, "📊 Функция истории запросов в разработке");
    } elseif ($text === '❓ Помощь') {
        sendHelp($chat_id);
    } elseif ($text === '🔍 Поиск в интернете') {
        $userMode->setMode($user_id, 'general');
        sendMessage($chat_id,
            "🔍 *Введите ваш запрос для поиска в интернете*\n\n" .
            "Например:\n" .
            "• Погода в Москве\n" .
            "• Курс доллара\n" .
            "• Новости сегодня\n" .
            "• Любой другой вопрос"
        );
    } else {
        sendMessage($chat_id, "Пожалуйста, выберите пункт меню или отправьте /menu");
    }
}

function handleGeneralMode($chat_id, $text) {
    global $user_id;
    try {
        sendAction($chat_id, 'typing');
        debug_log("General mode обработка для user $user_id: $text");

        $memory = new ConversationMemory();
        $memory->addMessage($user_id, 'user', $text);

        // Простые ответы на приветствия
        $hello_variants = ['привет', 'здравствуй', 'хай', 'ку', 'салют', 'добрый день', 'добрый вечер', 'доброе утро', 'ты тут', 'есть кто'];
        $text_lower = mb_strtolower($text);

        foreach ($hello_variants as $hello) {
            if (strpos($text_lower, $hello) !== false) {
                $responses = [
                    "👋 Привет! Чем могу помочь?",
                    "Здравствуйте! Я здесь, готов помочь с оценкой квартиры или ответить на вопросы.",
                    "Да, я тут! Выберите режим в меню или просто спросите что-то.",
                    "Приветствую! Что вас интересует?"
                ];
                $response = $responses[array_rand($responses)];
                saveMessageToLaravel($user_id, 'assistant', $response);
                $memory->addMessage($user_id, 'assistant', $response);
                sendMessage($chat_id, $response);
                return;
            }
        }

        if (handleSpecialQueries($chat_id, $text)) {
            debug_log("Обработан специальный запрос");
            return;
        }

        $last_messages = $memory->getLastMessages($user_id, 5);
        $is_follow_up = isFollowUpQuestion($text, $last_messages);

        if ($is_follow_up) {
            debug_log("Обнаружен уточняющий вопрос");
            $last_search_query = getLastSearchQuery($last_messages);

            if ($last_search_query) {
                $full_query = $last_search_query . " " . $text;
                debug_log("Расширенный запрос: $full_query");
                sendMessage($chat_id, "🔍 *Ищу информацию с учетом предыдущего вопроса...*");
                $web_data = searchInternet($full_query);

                if ($web_data) {
                    $response = formatSearchResponse($web_data, $text);
                    saveMessageToLaravel($user_id, 'assistant', $response);
                    $memory->addMessage($user_id, 'assistant', $response);
                    sendMessage($chat_id, $response);
                    return;
                }
            }
        }

        $history = $memory->getMessagesForGPT($user_id,
            'Ты полезный помощник. Отвечай на русском языке, учитывая историю диалога. Будь дружелюбным и информативным.'
        );

        $needs_web_search = checkIfNeedsWebSearch($text, $last_messages);

        if ($needs_web_search) {
            sendMessage($chat_id, "🔍 *Ищу актуальную информацию...*");
            $search_context = $memory->getContextForSearch($user_id);
            $enhanced_query = $search_context . "\nТекущий вопрос: " . $text;
            $web_data = searchInternet($enhanced_query);

            if ($web_data) {
                $response = formatSearchResponse($web_data, $text);
                saveMessageToLaravel($user_id, 'assistant', $response);
                $memory->addMessage($user_id, 'assistant', $response);
                sendMessage($chat_id, $response);
                debug_log("Ответ с поиском отправлен");
                return;
            }
        }

        $response = askGPTWithHistory($history);

        if ($response) {
            saveMessageToLaravel($user_id, 'assistant', $response);
            $memory->addMessage($user_id, 'assistant', $response);
            sendMessage($chat_id, $response);
            debug_log("GPT ответ отправлен");
        } else {
            sendMessage($chat_id, "❌ Не удалось получить ответ. Попробуйте еще раз.");
        }

    } catch (Exception $e) {
        debug_log("Ошибка в handleGeneralMode: " . $e->getMessage());
        sendMessage($chat_id, "❌ Ошибка: " . $e->getMessage());
    }
}

function isFollowUpQuestion($text, $last_messages) {
    $text_lower = mb_strtolower($text);

    if (mb_strlen($text) < 20) {
        $follow_up_words = ['а в', 'а как', 'а что', 'а где', 'а когда', 'там', 'здесь', 'тоже', 'также', 'и в'];
        foreach ($follow_up_words as $word) {
            if (strpos($text_lower, $word) === 0) {
                return true;
            }
        }
    }

    foreach ($last_messages as $msg) {
        if ($msg['role'] === 'assistant' &&
            (strpos($msg['content'], '🌤') !== false || strpos($msg['content'], '🔍') !== false)) {
            return true;
        }
    }

    return false;
}

function getLastSearchQuery($last_messages) {
    foreach ($last_messages as $msg) {
        if ($msg['role'] === 'user') {
            if (preg_match('/(погода|новости|курс).*?([а-яё\s\-]+)/ui', $msg['content'], $matches)) {
                return $matches[0];
            }
        }
    }
    return null;
}

function formatSearchResponse($web_data, $original_query) {
    $city = '';
    if (preg_match('/(?:в|во|для)\s+([а-яё\s\-]+)/ui', $original_query, $matches)) {
        $city = trim($matches[1]);
    }

    $response = $web_data . "\n\n";
    $response .= "❓ Хотите узнать погоду в другом городе? Просто спросите!\n";
    $response .= "Например: *А в Москве?* или *Что в Гомеле?*";

    return $response;
}

function askGPTWithHistory($messages) {
    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) {
            debug_log("CURL Error: " . curl_error($ch));
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        if ($http_code === 200) {
            $result = json_decode($response, true);
            return $result['choices'][0]['message']['content'] ?? null;
        }

        debug_log("askGPT error: HTTP $http_code, Response: $response");
        return null;

    } catch (Exception $e) {
        debug_log("askGPT exception: " . $e->getMessage());
        return null;
    }
}

function checkIfNeedsWebSearch($text, $last_messages = []) {
    $text_lower = mb_strtolower($text);

    $context = '';
    foreach ($last_messages as $msg) {
        if ($msg['role'] === 'user') {
            $context .= mb_strtolower($msg['content']) . ' ';
        }
    }

    $must_search_keywords = [
        'погод', 'прогноз', 'температур', 'дожд', 'снег', 'ветер',
        'новост', 'событи', 'происшеств', 'последние',
        'курс', 'доллар', 'евро', 'юань', 'нефт', 'валют',
        'сегодня', 'завтра', 'вчера', 'сейчас', 'актуальн',
        '2024', '2025', '2026', 'цена', 'стоимость',
        'сколько стоит', 'какой сейчас'
    ];

    foreach ($must_search_keywords as $keyword) {
        if (strpos($text_lower, $keyword) !== false) {
            return true;
        }
    }

    foreach ($must_search_keywords as $keyword) {
        if (strpos($context, $keyword) !== false) {
            return true;
        }
    }

    if (preg_match('/(а что|а как|а где|а когда|а почему|а какой)/ui', $text)) {
        return true;
    }

    return false;
}

function handleSpecialQueries($chat_id, $text) {
    if (preg_match('/(?:погода|прогноз|температура|холодно|тепло).*?(?:в|во|для)?\s*([а-яё\s\-]+)?/ui', $text, $matches)) {
        $city = isset($matches[1]) ? trim($matches[1]) : '';

        if (empty($city) && preg_match('/^а?\s*в\s+([а-яё\s\-]+)$/ui', $text, $city_matches)) {
            $city = trim($city_matches[1]);
        }

        sendMessage($chat_id, "🔍 *Ищу погоду" . (!empty($city) ? " для города " . $city : "") . "...*");
        $weather = searchWeather($text);

        if ($weather) {
            return true;
        } else {
            $city_display = !empty($city) ? $city : "указанном городе";
            sendMessage($chat_id, "❌ Не удалось найти погоду для " . $city_display .
                "\n\nПопробуйте уточнить название города, например:\n" .
                "• Погода в Москве\n" .
                "• Какая температура в Питере\n" .
                "• Прогноз в Минске\n" .
                "• Орша погода\n" .
                "• В Гомеле");
        }
        return true;
    }

    if (preg_match('/(курс|цена) (доллар|евро|юань|рубль)/i', $text, $matches)) {
        $currency = $matches[2];
        $rate = getCurrencyRate($currency);
        if ($rate) {
            sendMessage($chat_id, "💰 Курс {$currency} на сегодня: {$rate} руб.");
        } else {
            sendMessage($chat_id, "❌ Не удалось получить курс валют");
        }
        return true;
    }

    return false;
}

function getCurrencyRate($currency) {
    try {
        $url = "https://www.cbr-xml-daily.ru/daily_json.js";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            debug_log("CBR API error: HTTP $http_code");
            return null;
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['Valute'])) {
            debug_log("CBR API: Invalid response format");
            return null;
        }

        $currency_map = [
            'доллар' => 'USD',
            'евро' => 'EUR',
            'юань' => 'CNY'
        ];

        $code = $currency_map[$currency] ?? strtoupper($currency);

        if (isset($data['Valute'][$code])) {
            return number_format($data['Valute'][$code]['Value'], 2, '.', ' ');
        }

        return null;

    } catch (Exception $e) {
        debug_log("getCurrencyRate error: " . $e->getMessage());
        return null;
    }
}

function searchInternet($query) {
    try {
        debug_log("Поиск в интернете: " . $query);

        $results = [];

        $wiki_results = searchWikipedia($query);
        if ($wiki_results) {
            $results[] = $wiki_results;
            debug_log("Найдены данные в Wikipedia");
        }

        if (!$wiki_results) {
            $wiki_en_results = searchWikipediaEn($query);
            if ($wiki_en_results) {
                $results[] = $wiki_en_results;
                debug_log("Найдены данные в английской Wikipedia");
            }
        }

        $ddg_results = searchDuckDuckGo($query);
        if ($ddg_results && strlen($ddg_results) > 50) {
            $results[] = $ddg_results;
            debug_log("Найдены данные в DuckDuckGo");
        }

        if (strpos(mb_strtolower($query), 'погода') !== false) {
            $weather_results = searchWeather($query);
            if ($weather_results) {
                return $weather_results;
            }
        }

        if (strpos(mb_strtolower($query), 'новости') !== false) {
            $news_results = searchNews();
            if ($news_results) {
                $results[] = $news_results;
            }
        }

        if (empty($results)) {
            debug_log("Ничего не найдено в интернете");
            return null;
        }

        $final_result = implode("\n\n━━━━━━━━━━━━━━━━━━━━\n\n", $results);
        debug_log("Результат поиска: " . substr($final_result, 0, 200) . "...");

        return $final_result;

    } catch (Exception $e) {
        debug_log("searchInternet error: " . $e->getMessage());
        return null;
    }
}

function searchWeather($query) {
    try {
        $city = extractCityFromQuery($query);

        if (!$city) {
            if (preg_match('/(погода|прогноз|температура)/ui', $query)) {
                $city = 'Минск';
            } else {
                return null;
            }
        }

        $city = trim($city);
        debug_log("Поиск погоды для города: '$city'");

        $url = "https://wttr.in/" . urlencode($city) . "?format=%t+%c+%w+%h+%p&lang=ru";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl/7.68.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $response && strlen(trim($response)) > 3) {
            $response = trim($response);
            $response = preg_replace('/\s+/', ' ', $response);

            $parts = explode(' ', $response);
            $temp = $parts[0] ?? '';
            $condition = $parts[1] ?? '';
            $wind = isset($parts[2]) ? $parts[2] . ' ' . ($parts[3] ?? '') : '';
            $humidity = $parts[4] ?? '';
            $precipitation = $parts[5] ?? '';

            $weather_text = "🌤 *Погода в {$city}:*\n\n";
            $weather_text .= "🌡 Температура: {$temp}\n";

            if ($condition) {
                $condition = str_replace(['+', '-', '°C'], '', $condition);
                $weather_text .= "☁️ Состояние: " . getWeatherCondition($condition) . "\n";
            }

            if ($wind) {
                $weather_text .= "💨 Ветер: {$wind}\n";
            }

            if ($humidity) {
                $weather_text .= "💧 Влажность: {$humidity}\n";
            }

            if ($precipitation && $precipitation !== '0.0mm') {
                $weather_text .= "☔️ Осадки: {$precipitation}\n";
            }

            $weather_text .= "\n❓ Хотите узнать погоду в другом городе? Просто спросите!\n";
            $weather_text .= "Например: *А в Москве?* или *Что в Гомеле?*";

            return $weather_text;
        } else {
            debug_log("Weather API вернул код $http_code для города '$city', ответ: $response");

            $alt_url = "https://v2.wttr.in/" . urlencode($city) . "?format=%t+%c+%w&lang=ru";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $alt_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'curl/7.68.0');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200 && $response && strlen(trim($response)) > 3) {
                return "🌤 *Погода в {$city}:*\n" . trim($response) .
                    "\n\n❓ Хотите узнать погоду в другом городе? Просто спросите!";
            }
        }

        return null;

    } catch (Exception $e) {
        debug_log("searchWeather error: " . $e->getMessage());
        return null;
    }
}

function getWeatherCondition($code) {
    $conditions = [
        'Clear' => 'Ясно',
        'Sunny' => 'Солнечно',
        'Partly' => 'Переменная облачность',
        'Cloud' => 'Облачно',
        'Overcast' => 'Пасмурно',
        'Rain' => 'Дождь',
        'Snow' => 'Снег',
        'Sleet' => 'Мокрый снег',
        'Thunder' => 'Гроза',
        'Blizzard' => 'Метель',
        'Fog' => 'Туман',
        'Mist' => 'Туманно',
        'Hail' => 'Град'
    ];

    foreach ($conditions as $key => $value) {
        if (stripos($code, $key) !== false) {
            return $value;
        }
    }

    return $code;
}

function extractCityFromQuery($query) {
    $query = mb_strtolower(trim($query));

    $patterns = [
        '/(?:в|во|для|город|городе)\s+([а-яё\s\-]+)/ui',
        '/погода\s+в\s+([а-яё\s\-]+)/ui',
        '/погода\s+([а-яё\s\-]+)/ui',
        '/([а-яё\s\-]+)\s+погода/ui',
        '/^([а-яё\s\-]+)$/ui'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query, $matches)) {
            $city = trim($matches[1]);
            $city = preg_replace('/[^\p{L}\s\-]/u', '', $city);
            $city = trim($city);

            $skip_words = ['интернете', 'городе', 'мире', 'стране', 'пожалуйста', 'спасибо', 'сегодня', 'завтра'];
            if (!in_array($city, $skip_words) && strlen($city) > 1) {
                return $city;
            }
        }
    }

    if (preg_match('/^а?\s*в\s+([а-яё\s\-]+)$/ui', $query, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function searchNews() {
    try {
        $url = "https://news.google.com/rss?hl=ru&gl=RU&ceid=RU:ru";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $xml = simplexml_load_string($response);
            $news_items = [];

            if ($xml && isset($xml->channel->item)) {
                $count = 0;
                foreach ($xml->channel->item as $item) {
                    if ($count >= 5) break;

                    $title = (string)$item->title;
                    $link = (string)$item->link;
                    $date = date('d.m.Y H:i', strtotime((string)$item->pubDate));

                    $news_items[] = "• *{$title}*\n  🕐 {$date}\n  [Читать]({$link})";
                    $count++;
                }
            }

            if (!empty($news_items)) {
                return "📰 *Последние новости:*\n\n" . implode("\n\n", $news_items);
            }
        }

        return null;

    } catch (Exception $e) {
        debug_log("searchNews error: " . $e->getMessage());
        return null;
    }
}

function searchWikipediaEn($query) {
    try {
        $url = "https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=" . urlencode($query) . "&format=json&srlimit=2";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramBot/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['query']['search']) && !empty($data['query']['search'])) {
            $result = "📚 *From Wikipedia (English):*\n\n";
            foreach ($data['query']['search'] as $item) {
                $title = $item['title'];
                $snippet = strip_tags($item['snippet']);
                $result .= "• *{$title}*\n";
                $result .= "  " . $snippet . "...\n";
                $result .= "  [Read more](https://en.wikipedia.org/wiki/" . urlencode($title) . ")\n\n";
            }
            return $result;
        }

        return null;

    } catch (Exception $e) {
        debug_log("searchWikipediaEn error: " . $e->getMessage());
        return null;
    }
}

function searchWikipedia($query) {
    try {
        $url = "https://ru.wikipedia.org/w/api.php?action=query&list=search&srsearch=" . urlencode($query) . "&format=json&srlimit=3";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramBot/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            debug_log("Wikipedia API error: HTTP $http_code");
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data['query']['search']) && !empty($data['query']['search'])) {
            $result = "📚 *Из Wikipedia:*\n\n";
            foreach ($data['query']['search'] as $item) {
                $title = $item['title'];
                $snippet = strip_tags($item['snippet']);
                $snippet = str_replace(['&nbsp;', '&mdash;'], [' ', '-'], $snippet);
                $result .= "• *{$title}*\n";
                $result .= "  " . $snippet . "...\n";
                $result .= "  [Читать далее](https://ru.wikipedia.org/wiki/" . urlencode($title) . ")\n\n";
            }
            return $result;
        }

        return null;

    } catch (Exception $e) {
        debug_log("searchWikipedia error: " . $e->getMessage());
        return null;
    }
}

function searchDuckDuckGo($query) {
    try {
        $url = "https://api.duckduckgo.com/?q=" . urlencode($query) . "&format=json&no_html=1&skip_disambig=1&t=telegram_bot";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TelegramBot/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            debug_log("DuckDuckGo API error: HTTP $http_code");
            return null;
        }

        $data = json_decode($response, true);

        $result = "🦆 *Результаты поиска:*\n\n";
        $has_content = false;

        if (!empty($data['Abstract'])) {
            $result .= $data['Abstract'] . "\n\n";
            if (!empty($data['AbstractURL'])) {
                $result .= "🔗 [Источник](" . $data['AbstractURL'] . ")\n\n";
            }
            $has_content = true;
        }

        if (!empty($data['RelatedTopics']) && is_array($data['RelatedTopics'])) {
            $count = 0;
            foreach ($data['RelatedTopics'] as $topic) {
                if ($count >= 3) break;
                if (isset($topic['Text']) && !empty($topic['Text'])) {
                    $result .= "• " . $topic['Text'] . "\n";
                    if (isset($topic['FirstURL'])) {
                        $result .= "  [Ссылка](" . $topic['FirstURL'] . ")\n";
                    }
                    $result .= "\n";
                    $has_content = true;
                    $count++;
                }
            }
        }

        return $has_content ? $result : null;

    } catch (Exception $e) {
        debug_log("searchDuckDuckGo error: " . $e->getMessage());
        return null;
    }
}

function handleExpertMode($user_id, $chat_id, $text, $userMode) {
    try {
        sendAction($chat_id, 'typing');

        $memory = new ConversationMemory();

        $is_refinement = isRefinementQuery($text);

        if ($is_refinement) {
            debug_log("Обнаружен уточняющий запрос: $text");

            $history = $memory->getLastMessages($user_id, 20);
            $last_assessment = getLastAssessmentFromHistory($history);

            if ($last_assessment) {
                debug_log("Найдена предыдущая оценка: " . json_encode($last_assessment));

                $full_query = buildFullQueryFromAssessment($last_assessment, $text);
                debug_log("Полный запрос с контекстом: $full_query");

                $memory->addMessage($user_id, 'user', "[Экспертная оценка] [уточнение] " . $text);

                $parser = new ApartmentParser();
                $params = $parser->parse($full_query);

                if (!empty($params)) {
                    sendMessage($chat_id, "🔄 *Уточняю оценку с учетом контекста...*");

                    $params['previous_assessment'] = $last_assessment;

                    processExpertEstimation($user_id, $chat_id, $full_query, $params, $memory, $userMode, $last_assessment);
                    return;
                }
            }
        }

        $memory->addMessage($user_id, 'user', "[Экспертная оценка] " . $text);

        $parser = new ApartmentParser();
        $params = $parser->parse($text);

        if (empty($params)) {
            $history = $memory->getLastMessages($user_id, 10);
            $last_assessment = getLastAssessmentFromHistory($history);

            if ($last_assessment && mb_strlen($text) < 30) {
                $full_query = $last_assessment['original_query'] . " " . $text;
                $params = $parser->parse($full_query);

                if (!empty($params)) {
                    sendMessage($chat_id, "🔄 *Уточняю оценку...*");
                    processExpertEstimation($user_id, $chat_id, $full_query, $params, $memory, $userMode, $last_assessment);
                    return;
                }
            }

            handleExpertParsingError($user_id, $chat_id, $text, $memory, $last_assessment ?? null);
            return;
        }

        processExpertEstimation($user_id, $chat_id, $text, $params, $memory, $userMode);

    } catch (Exception $e) {
        debug_log("Expert mode error: " . $e->getMessage());
        $error_msg = "❌ Ошибка при оценке: " . $e->getMessage();
        sendMessage($chat_id, $error_msg);

        $memory = new ConversationMemory();
        $memory->addMessage($user_id, 'assistant', $error_msg);
    }
}

function isRefinementQuery($text) {
    $text_lower = mb_strtolower(trim($text));

    $refinement_keywords = [
        'а если', 'с ремонтом', 'без отделки', 'этажом', 'выше', 'ниже',
        'другой', 'там же', 'такая же', 'похожая', 'в центре', 'на окраине',
        'с евро', 'с косметическим', 'свежий', 'старый', 'новый',
        'евроремонт', 'косметика', 'черновая', 'чистовая'
    ];

    foreach ($refinement_keywords as $keyword) {
        if (strpos($text_lower, $keyword) !== false) {
            return true;
        }
    }

    if (mb_strlen($text) < 30) {
        return true;
    }

    return false;
}

function buildFullQueryFromAssessment($assessment, $refinement) {
    $base_parts = [];

    if (!empty($assessment['city'])) {
        $base_parts[] = $assessment['city'];
    } else {
        $base_parts[] = 'Минск';
    }

    if (!empty($assessment['address'])) {
        $base_parts[] = $assessment['address'];
    }

    if (!empty($assessment['district'])) {
        $base_parts[] = $assessment['district'] . ' район';
    }

    if (!empty($assessment['complex'])) {
        $base_parts[] = 'ЖК ' . $assessment['complex'];
    }

    if (!empty($assessment['area'])) {
        $base_parts[] = $assessment['area'] . ' м²';
    }

    if (!empty($assessment['rooms'])) {
        $base_parts[] = $assessment['rooms'] . ' комнаты';
    }

    if (!empty($assessment['floor'])) {
        $base_parts[] = $assessment['floor'] . ' этаж';
    }

    if (!empty($assessment['repair'])) {
        $base_parts[] = $assessment['repair'];
    }

    $base_query = implode(', ', $base_parts);

    if (strlen($refinement) > 20 && strpos($refinement, 'м²') !== false) {
        return $refinement;
    }

    return $base_query . ', ' . $refinement;
}

function getLastAssessmentFromHistory($history) {
    $last_assessment = null;

    for ($i = count($history) - 1; $i >= 0; $i--) {
        $msg = $history[$i];

        if ($msg['role'] === 'assistant' && strpos($msg['content'], 'Экспертная оценка') !== false) {
            $content = $msg['content'];

            $assessment = [
                'city' => extractValueFromText($content, 'Город:', "\n"),
                'district' => extractValueFromText($content, 'Район:', "\n"),
                'complex' => extractValueFromText($content, 'ЖК:', "\n"),
                'address' => extractValueFromText($content, 'Адрес:', "\n"),
                'area' => extractValueFromText($content, 'Площадь:', 'м²'),
                'rooms' => extractValueFromText($content, 'Комнат:', "\n"),
                'floor' => extractValueFromText($content, 'Этаж:', "\n"),
                'repair' => extractValueFromText($content, 'Ремонт:', "\n"),
                'min_price' => extractNumberFromText($content, 'от', 'до'),
                'max_price' => extractNumberFromText($content, 'до', '₽'),
                'avg_price' => extractNumberFromText($content, 'Средняя:', '₽')
            ];

            for ($j = $i - 1; $j >= 0; $j--) {
                if ($history[$j]['role'] === 'user' &&
                    strpos($history[$j]['content'], '[Экспертная оценка]') === 0 &&
                    strpos($history[$j]['content'], '[уточнение]') === false) {

                    $user_msg = $history[$j]['content'];
                    $assessment['original_query'] = str_replace('[Экспертная оценка] ', '', $user_msg);

                    if (preg_match('/([а-яё\s\-]+),\s*([^,]+)/u', $assessment['original_query'], $addr_matches)) {
                        if (empty($assessment['address'])) {
                            $assessment['address'] = trim($addr_matches[2]);
                        }
                    }

                    break;
                }
            }

            $last_assessment = $assessment;
            break;
        }
    }

    return $last_assessment;
}

function extractValueFromText($text, $start, $end) {
    $pattern = '/' . preg_quote($start, '/') . '\s*(.*?)\s*' . preg_quote($end, '/') . '/u';
    if (preg_match($pattern, $text, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function extractNumberFromText($text, $after, $before) {
    $pattern = '/' . preg_quote($after, '/') . '\s*([\d\s]+)\s*' . preg_quote($before, '/') . '/u';
    if (preg_match($pattern, $text, $matches)) {
        return (int)str_replace(' ', '', $matches[1]);
    }
    return null;
}

function handleExpertParsingError($user_id, $chat_id, $text, $memory, $last_assessment = null) {
    if ($last_assessment) {
        $suggestions = [];

        if (empty($last_assessment['repair'])) {
            $suggestions[] = [
                ['text' => '🔨 С ремонтом', 'callback_data' => 'expert_repair_euro'],
                ['text' => '🏗 Без отделки', 'callback_data' => 'expert_repair_no']
            ];
        }

        if (empty($last_assessment['floor'])) {
            $suggestions[] = [
                ['text' => '📊 1-3 этаж', 'callback_data' => 'expert_floor_low'],
                ['text' => '📊 4-7 этаж', 'callback_data' => 'expert_floor_mid'],
                ['text' => '📊 8+ этаж', 'callback_data' => 'expert_floor_high']
            ];
        }

        if (empty($last_assessment['district']) && !empty($last_assessment['city'])) {
            $suggestions[] = [
                ['text' => '🏘 Центр', 'callback_data' => 'expert_district_center'],
                ['text' => '🏘 Спальный', 'callback_data' => 'expert_district_sleep']
            ];
        }

        if (!empty($suggestions)) {
            $suggestions[] = [
                ['text' => '🔄 Новая оценка', 'callback_data' => 'mode_expert'],
                ['text' => '🏠 Меню', 'callback_data' => 'menu']
            ];

            sendMessageWithKeyboard($chat_id,
                "❓ *Уточните параметры для более точной оценки*\n\n" .
                "Я нашел предыдущую оценку. Выберите, что уточнить:",
                ['inline_keyboard' => $suggestions]
            );
            return;
        }
    }

    $error_msg = "❌ Не удалось распознать параметры.\n\n" .
        "Пример правильного запроса:\n" .
        "`Квартира в Минске на Пионерской, 50м², 3 этаж, евроремонт, 2 комнаты`\n\n" .
        "Или уточните:\n" .
        "• Какой город?\n" .
        "• Какая площадь?\n" .
        "• Сколько комнат?\n" .
        "• Какой ремонт?";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🏢 Новая оценка', 'callback_data' => 'mode_expert'],
                ['text' => '🏠 Меню', 'callback_data' => 'menu']
            ]
        ]
    ];

    sendMessageWithKeyboard($chat_id, $error_msg, $keyboard);
    $memory->addMessage($user_id, 'assistant', $error_msg);
}

function processExpertEstimation($user_id, $chat_id, $original_text, $params, $memory, $userMode, $previous_assessment = null) {
    $userMode->saveLastQuery($user_id, $original_text);

    if ($previous_assessment) {
        $params['context'] = [
            'previous_city' => $previous_assessment['city'] ?? null,
            'previous_district' => $previous_assessment['district'] ?? null,
            'previous_price' => $previous_assessment['avg_price'] ?? null
        ];
    }

    $cache = new EstimateCache();
    $hash = $cache->createHash($params);
    $cached = $cache->get($hash);

    if ($cached) {
        $estimate = $cached['response_json'];
        sendMessage($chat_id, "🔍 Нашел похожую оценку в кэше");
    } else {
        sendMessage($chat_id, "⏳ Анализирую объект...");

        $gpt = new GPTClient();

        try {
            $estimate = $gpt->ask($params);
            if ($estimate) {
                $cache->save($hash, $original_text, $params, $estimate);
            } else {
                sendMessage($chat_id, "❌ Не удалось выполнить оценку. Попробуйте позже.");
                return;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            debug_log("GPT Error in processExpertEstimation: " . $error_message);

            if (strpos($error_message, '429') !== false) {
                sendMessage($chat_id, "⚠️ Превышен лимит запросов к OpenAI. Пожалуйста, подождите 1-2 минуты и попробуйте снова.");
            } else {
                sendMessage($chat_id, "❌ Ошибка при оценке: " . $error_message);
            }
            return;
        }
    }

    $pdf = new PDFGenerator();
    $pdf_file = $pdf->generate($params, $estimate);

    $response = "🏠 *Экспертная оценка*\n\n";
    $response .= "📋 *Параметры:*\n";

    if (!empty($params['city'])) $response .= "• Город: {$params['city']}\n";
    if (!empty($params['district'])) $response .= "• Район: {$params['district']}\n";
    if (!empty($params['complex'])) $response .= "• ЖК: {$params['complex']}\n";
    if (!empty($params['address'])) $response .= "• Адрес: {$params['address']}\n";
    if (!empty($params['area'])) $response .= "• Площадь: {$params['area']} м²\n";
    if (!empty($params['rooms'])) $response .= "• Комнат: {$params['rooms']}\n";
    if (!empty($params['floor'])) $response .= "• Этаж: {$params['floor']}\n";
    if (!empty($params['repair'])) $response .= "• Ремонт: {$params['repair']}\n";

    $response .= "\n💰 *Цена:* от " . number_format($estimate['min_price'], 0, '.', ' ') .
        " до " . number_format($estimate['max_price'], 0, '.', ' ') . " ₽\n";
    $response .= "📊 *Средняя:* " . number_format($estimate['avg_price'], 0, '.', ' ') . " ₽\n";
    $response .= "📈 *Цена за м²:* " . number_format($estimate['price_per_m2'], 0, '.', ' ') . " ₽\n\n";
    $response .= "📎 *Подробный отчет* в PDF файле\n\n";

    $response .= "❓ *Что дальше?*\n";

    if (empty($params['repair'])) {
        $response .= "• Уточните ремонт: *с ремонтом* или *без отделки*\n";
    }
    if (empty($params['floor'])) {
        $response .= "• Уточните этаж: *этажом выше* или *первый этаж*\n";
    }
    if (empty($params['district']) && !empty($params['city'])) {
        $response .= "• Уточните район: *в центре* или *спальный район*\n";
    }

    $response .= "• Сравните с другой квартирой\n";
    $response .= "• Или отправьте /menu для возврата в главное меню";

    // Сохраняем ответ бота в админку
    saveMessageToLaravel($user_id, 'assistant', $response);

    sendMessage($chat_id, $response);
    $memory->addMessage($user_id, 'assistant', $response);

    if (file_exists($pdf_file)) {
        sendDocument($chat_id, $pdf_file);
        unlink($pdf_file);
    }
}

function showUserQueries($user_id, $chat_id) {
    sendMessage($chat_id, "📊 Функция истории запросов в разработке");
}

function sendMessage($chat_id, $text) {
    global $user_id;

    $ch = curl_init(API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        debug_log("sendMessage error: HTTP $http_code, Response: $result");
    }
}

function sendMessageWithKeyboard($chat_id, $text, $keyboard) {
    global $user_id;

    $ch = curl_init(API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        debug_log("sendMessageWithKeyboard error: HTTP $http_code, Response: $result");
    }
}

function sendAction($chat_id, $action) {
    $ch = curl_init(API_URL . 'sendChatAction');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chat_id,
        'action' => $action
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function sendDocument($chat_id, $file_path, $caption = '') {
    global $user_id;

    if (!file_exists($file_path)) {
        debug_log("SendDocument error: File not found - " . $file_path);
        sendMessage($chat_id, "❌ Ошибка: файл не найден");
        return false;
    }

    $file_size = filesize($file_path);
    if ($file_size > 50 * 1024 * 1024) {
        debug_log("SendDocument error: File too large - " . $file_size . " bytes");
        sendMessage($chat_id, "❌ Файл слишком большой для отправки");
        return false;
    }

    $ch = curl_init(API_URL . 'sendDocument');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path),
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        debug_log("Document sent successfully");
        return true;
    } else {
        debug_log("Send document failed. HTTP code: " . $http_code . " Response: " . $response);
        sendMessage($chat_id, "❌ Не удалось отправить PDF. Попробуйте еще раз.");
        return false;
    }
}
?>