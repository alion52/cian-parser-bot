<?php
class ApartmentParser {

    /**
     * Извлекает параметры квартиры из текста
     */
    public function parse($text) {
        $params = [];
        $text_lower = mb_strtolower($text);

        // Город
        $cities = ['Москва', 'Санкт-Петербург', 'Минск', 'Гомель', 'Брест'];
        foreach ($cities as $city) {
            if (strpos($text, $city) !== false) {
                $params['city'] = $city;
                break;
            }
        }
        if (!isset($params['city'])) $params['city'] = 'Москва';

        // Жилой комплекс
        if (preg_match('/(?:ЖК|жилой\s+комплекс)[\s:]*["«]?([^"»,\n]+)/iu', $text, $matches)) {
            $params['complex'] = trim($matches[1]);
        } elseif (preg_match('/["«]([^"»]+)["»]/u', $text, $matches)) {
            $params['complex'] = trim($matches[1]);
        }

        // Площадь
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*м[²2]|м2/i', $text, $matches)) {
            $params['area'] = floatval(str_replace(',', '.', $matches[1]));
        }

        // Этаж
        if (preg_match('/(\d+)[-\s]?этаж/i', $text, $matches)) {
            $params['floor'] = intval($matches[1]);
        }

        // Количество комнат - ПРИОРИТЕТНЫЙ ПОИСК
        $rooms_found = false;

        // Вариант 1: "Кол-во комнат: 2"
        if (preg_match('/(?:кол-во|количество)\s+комнат[:\s]+(\d+)/iu', $text, $matches)) {
            $params['rooms'] = intval($matches[1]);
            $rooms_found = true;
        }
        // Вариант 2: "2 комнаты" или "2-комнатная"
        elseif (preg_match('/(\d+)[-\s]?комнат|комн|комната|кк/i', $text, $matches)) {
            $params['rooms'] = intval($matches[1]);
            $rooms_found = true;
        }
        // Вариант 3: "комнат: 2"
        elseif (preg_match('/комнат[:\s]+(\d+)/iu', $text, $matches)) {
            $params['rooms'] = intval($matches[1]);
            $rooms_found = true;
        }

        // Если комнаты не найдены, не добавляем параметр
        if (!$rooms_found) {
            // Не устанавливаем rooms
        }

        // Ремонт
        $renovation_types = [
            'дизайнерский' => 'дизайнерский',
            'евро' => 'евро',
            'косметический' => 'косметический',
            'чистовой' => 'чистовой',
            'черновой' => 'черновой',
            'под отделку' => 'под отделку',
            'без отделки' => 'черновой'
        ];

        foreach ($renovation_types as $search => $value) {
            if (strpos($text_lower, $search) !== false) {
                $params['renovation'] = $value;
                break;
            }
        }

        // Адрес
        if (preg_match('/(?:Адрес|адрес)[\s:]*([^,\n]+)/iu', $text, $matches)) {
            $params['address'] = trim($matches[1]);
        }

        // Отладка в лог
        error_log("Parsed params: " . print_r($params, true));

        return $params;
    }

    /**
     * Формирует текст запроса для GPT
     */
    public function buildPrompt($params) {
        $prompt = "Оцени стоимость квартиры в {$params['city']} со следующими параметрами:\n";

        if (!empty($params['complex'])) {
            $prompt .= "- Жилой комплекс: {$params['complex']}\n";
        }
        if (!empty($params['address'])) {
            $prompt .= "- Адрес: {$params['address']}\n";
        }
        if (!empty($params['area'])) {
            $prompt .= "- Площадь: {$params['area']} м²\n";
        }
        if (!empty($params['rooms'])) {
            $prompt .= "- Комнат: {$params['rooms']}\n";
        }
        if (!empty($params['floor'])) {
            $prompt .= "- Этаж: {$params['floor']}\n";
        }
        if (!empty($params['renovation'])) {
            $prompt .= "- Ремонт: {$params['renovation']}\n";
        }

        $prompt .= "\nДай подробную экспертную оценку с анализом рынка и обоснованием.";

        return $prompt;
    }

    /**
     * Проверяет, валидны ли параметры перед сохранением в кэш
     */
    public function validateParams($params) {
        // Проверяем, что площадь не нулевая
        if (empty($params['area']) || $params['area'] <= 0) {
            return false;
        }
        // Проверяем, что комнаты не нулевые (если параметр существует)
        if (isset($params['rooms']) && $params['rooms'] <= 0) {
            return false;
        }
        return true;
    }
}