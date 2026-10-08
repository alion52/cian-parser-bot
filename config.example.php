<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('TELEGRAM_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
define('OPENAI_API_KEY', $_ENV['OPENAI_API_KEY'] ?? '');
define('API_URL', 'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/');

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'bot');
define('DB_USER', $_ENV['DB_USER'] ?? 'bot_user');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
