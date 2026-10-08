<?php
require_once 'config.php';

$url = "https://bot.example.com/bot.php";

$webhook_url = API_URL . "setWebhook?url=" . urlencode($url);

$ch = curl_init($webhook_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
curl_close($ch);

echo "Webhook установлен: " . $result;