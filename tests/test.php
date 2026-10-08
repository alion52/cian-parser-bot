<?php
// test.php - для диагностики
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

echo "✅ Vendor загружен\n";
echo "✅ Config загружен\n";
echo "✅ TELEGRAM_TOKEN: " . (defined('TELEGRAM_TOKEN') ? 'определен' : 'не определен') . "\n";
echo "✅ OPENAI_API_KEY: " . (defined('OPENAI_API_KEY') ? 'определен' : 'не определен') . "\n";

phpinfo();