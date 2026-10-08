<?php
require_once 'CianCache.php';

class CianParser {
    private $cache;

    public function __construct() {
        $this->cache = new CianCache();
    }

    public function parse($url) {
        // Очищаем URL от параметров
        $url = preg_replace('/\?.*$/', '', $url);
        $url = rtrim($url, '/');

        // Извлекаем ID объявления
        $cian_id = $this->extractId($url);
        if (!$cian_id) {
            throw new Exception("Неверная ссылка на ЦИАН");
        }

        // Проверяем кэш
        $cached = $this->cache->get($cian_id);
        if ($cached) {
            return json_decode($cached['data_json'], true);
        }

        // Получаем HTML страницы
        $html = $this->fetchPage($url);

        // Парсим данные из HTML
        $data = $this->parseHtml($html);

        if (empty($data)) {
            throw new Exception("Не удалось получить данные с сайта");
        }

        // Добавляем ID и URL
        $data['cian_id'] = $cian_id;
        $data['url'] = $url;
        $data['parsed_at'] = date('Y-m-d H:i:s');

        // Сохраняем в кэш
        $this->cache->save($cian_id, $url, $data);

        return $data;
    }

    private function extractId($url) {
        if (preg_match('/(\d+)\/?$/', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/\/(\d+)\//', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function fetchPage($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200) {
            throw new Exception("Ошибка загрузки страницы (HTTP код: $httpCode)");
        }

        return $html;
    }

    private function parseHtml($html) {
        $data = [];

        // ===== ЦЕНА =====
        // Ищем цену в формате "70 000 ₽/мес."
        if (preg_match('/(\d{1,3}(?:\s?\d{3})*)\s*₽\/мес\./', $html, $matches)) {
            $price = str_replace(' ', '', $matches[1]);
            $data['price'] = (int)$price;
        }
        // Альтернативный поиск цены
        elseif (preg_match('/<span[^>]*itemprop="price"[^>]*content="(\d+)"/', $html, $matches)) {
            $data['price'] = (int)$matches[1];
        }

        // ===== АДРЕС =====
        if (preg_match('/<address[^>]*itemprop="address"[^>]*>(.*?)<\/address>/s', $html, $matches)) {
            $data['address'] = trim(strip_tags($matches[1]));
        }
        elseif (preg_match('/"address":"([^"]+)"/', $html, $matches)) {
            $data['address'] = $matches[1];
        }

        // ===== ПЛОЩАДЬ =====
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*м²/i', $html, $matches)) {
            $data['area'] = (float)str_replace(',', '.', $matches[1]);
        }

        // ===== ПЛОЩАДЬ КУХНИ =====
        if (preg_match('/Площадь кухни[^>]*>([^<]+)/', $html, $matches)) {
            $kitchen = preg_replace('/[^0-9.]/', '', $matches[1]);
            if ($kitchen) {
                $data['kitchen_area'] = (float)$kitchen;
            }
        }

        // ===== ЭТАЖ =====
        if (preg_match('/(\d+)\s*[\/\|]\s*(\d+)\s*этаж/i', $html, $matches)) {
            $data['floor'] = (int)$matches[1];
            $data['floors_total'] = (int)$matches[2];
        }

        // ===== КОМНАТЫ =====
        if (preg_match('/(\d+)-комн/i', $html, $matches)) {
            $data['rooms'] = (int)$matches[1];
        }
        elseif (preg_match('/(\d+)\s*-?комнат/i', $html, $matches)) {
            $data['rooms'] = (int)$matches[1];
        }

        // ===== ГОД ПОСТРОЙКИ =====
        if (preg_match('/Год постройки[^>]*>(\d{4})/', $html, $matches)) {
            $data['build_year'] = (int)$matches[1];
        }

        // ===== ТИП ДОМА =====
        if (preg_match('/Тип дома[^>]*>([^<]+)/', $html, $matches)) {
            $data['house_type'] = trim($matches[1]);
        }

        // ===== ОПИСАНИЕ =====
        if (preg_match('/<meta[^>]*name="description"[^>]*content="([^"]+)"/', $html, $matches)) {
            $data['description'] = $matches[1];
        }
        elseif (preg_match('/<div[^>]*itemprop="description"[^>]*>(.*?)<\/div>/s', $html, $matches)) {
            $data['description'] = trim(strip_tags($matches[1]));
        }

        // ===== ФОТОГРАФИИ =====
        if (preg_match_all('/<img[^>]*src="([^"]+)"[^>]*class="[^"]*photo[^"]*"[^>]*>/i', $html, $matches)) {
            $data['photos'] = $matches[1];
        }
        // Если не нашли по классу, ищем любые изображения
        elseif (preg_match_all('/<img[^>]*src="([^"]+\.(?:jpg|jpeg|png|webp))"[^>]*>/i', $html, $matches)) {
            $data['photos'] = $matches[1];
        }

        // ===== ТИП СДЕЛКИ =====
        if (strpos($html, '/rent/') !== false) {
            $data['deal_type'] = 'rent';
        } elseif (strpos($html, '/sale/') !== false) {
            $data['deal_type'] = 'sale';
        }

        return $data;
    }
}