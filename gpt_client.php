<?php
require_once 'config.php';

class GPTClient {
    private $api_key;

    public function __construct() {
        $this->api_key = OPENAI_API_KEY;
    }

    public function ask($params) {
        $system_prompt = "ТЫ - ПРОФЕССИОНАЛЬНЫЙ ОЦЕНЩИК НЕДВИЖИМОСТИ с 20-летним опытом.
Твоя задача - давать подробную экспертную оценку рыночной стоимости квартир на основе предоставленных параметров.

ВАЖНО: Ты ДОЛЖЕН давать конкретную оценку с цифрами и анализом. Не отказывайся от оценки.

Твой ответ должен быть строго в формате JSON:
{
    \"min_price\": число (минимальная цена в рублях),
    \"max_price\": число (максимальная цена в рублях),
    \"avg_price\": число (средняя цена в рублях),
    \"price_per_m2\": число (цена за м²),
    \"confidence\": \"high|medium|low\",
    \"reasoning\": \"ПОДРОБНЫЙ ЭКСПЕРТНЫЙ АНАЛИЗ на русском языке\"
}";

        $user_prompt = $this->buildPrompt($params);

        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1500
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        $content = isset($result['choices'][0]['message']['content']) ? $result['choices'][0]['message']['content'] : '';
        // Извлекаем JSON из ответа
        preg_match('/\{.*\}/s', $content, $matches);
        if (empty($matches)) {
            return [
                'min_price' => 0,
                'max_price' => 0,
                'avg_price' => 0,
                'price_per_m2' => 0,
                'confidence' => 'medium',
                'reasoning' => $content
            ];
        }

        return json_decode($matches[0], true);
    }

    private function buildPrompt($params) {
        $prompt = "Проведи подробную экспертную оценку квартиры:\n\n";
        foreach ($params as $key => $value) {
            $prompt .= "• " . ucfirst($key) . ": " . $value . "\n";
        }
        return $prompt;
    }
}