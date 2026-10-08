<?php
require_once 'vendor/tecnickcom/tcpdf/tcpdf.php';

class CianPdfGenerator {

    public function generate($cian_data) {
        // Создаем новый PDF документ
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();

        // Заголовок
        $pdf->SetFont('freesans', 'B', 20);
        $pdf->SetTextColor(0, 84, 170);
        $pdf->Cell(0, 20, 'ПРЕЗЕНТАЦИЯ ОБЪЕКТА', 0, 1, 'C');
        $pdf->Ln(5);

        // Дата
        $pdf->SetFont('freesans', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, 'Дата: ' . date('d.m.Y H:i'), 0, 1, 'R');
        $pdf->Ln(5);

        // ID объявления
        if (!empty($cian_data['cian_id'])) {
            $pdf->SetFont('freesans', 'I', 9);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell(0, 4, 'ID: ' . $cian_data['cian_id'], 0, 1, 'R');
            $pdf->Ln(5);
        }

        // ===== ОСНОВНАЯ ИНФОРМАЦИЯ =====
        $pdf->SetFont('freesans', 'B', 16);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 10, 'Основная информация', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('freesans', '', 12);
        $pdf->SetFillColor(245, 245, 245);

        $fill = false;

        // Цена
        if (!empty($cian_data['price'])) {
            $price = is_numeric($cian_data['price']) ? number_format($cian_data['price'], 0, '.', ' ') : $cian_data['price'];
            $pdf->Cell(50, 10, '💰 Цена:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $price . ' ₽/мес.', 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Адрес
        if (!empty($cian_data['address'])) {
            $pdf->Cell(50, 10, '📍 Адрес:', 0, 0, 'L', $fill);
            $pdf->MultiCell(0, 10, $cian_data['address'], 0, 'L', $fill);
            $fill = !$fill;
        }

        // Площадь
        if (!empty($cian_data['area'])) {
            $area = is_numeric($cian_data['area']) ? number_format($cian_data['area'], 1, '.', ' ') . ' м²' : $cian_data['area'];
            $pdf->Cell(50, 10, '📐 Площадь:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $area, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Площадь кухни
        if (!empty($cian_data['kitchen_area'])) {
            $kitchen = is_numeric($cian_data['kitchen_area']) ? number_format($cian_data['kitchen_area'], 1, '.', ' ') . ' м²' : $cian_data['kitchen_area'];
            $pdf->Cell(50, 10, '🍳 Площадь кухни:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $kitchen, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Комнаты
        if (isset($cian_data['rooms'])) {
            $rooms = $cian_data['rooms'];
            if ($rooms == 0) $rooms = 'Студия';
            elseif ($rooms == 1) $rooms = '1 комната';
            elseif ($rooms == 2) $rooms = '2 комнаты';
            elseif ($rooms == 3) $rooms = '3 комнаты';
            elseif ($rooms > 3) $rooms = $rooms . ' комнат';

            $pdf->Cell(50, 10, '🛏 Комнат:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $rooms, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Этаж
        if (!empty($cian_data['floor']) || !empty($cian_data['floors_total'])) {
            $floor = '';
            if (!empty($cian_data['floor'])) $floor .= $cian_data['floor'];
            if (!empty($cian_data['floors_total'])) $floor .= '/' . $cian_data['floors_total'];
            if (!empty($floor)) {
                $pdf->Cell(50, 10, '📊 Этаж:', 0, 0, 'L', $fill);
                $pdf->Cell(0, 10, $floor, 0, 1, 'L', $fill);
                $fill = !$fill;
            }
        }

        // Тип дома
        if (!empty($cian_data['house_type'])) {
            $pdf->Cell(50, 10, '🏗 Тип дома:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $cian_data['house_type'], 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Год постройки
        if (!empty($cian_data['build_year'])) {
            $pdf->Cell(50, 10, '📅 Год постройки:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $cian_data['build_year'], 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Высота потолков
        if (!empty($cian_data['ceiling_height'])) {
            $pdf->Cell(50, 10, '📏 Высота потолков:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $cian_data['ceiling_height'] . ' м', 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Санузел
        if (!empty($cian_data['bathroom'])) {
            $pdf->Cell(50, 10, '🚽 Санузел:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $cian_data['bathroom'], 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Балкон
        if (!empty($cian_data['balcony'])) {
            $balcony = $cian_data['balcony'] . ' балкон';
            if ($cian_data['balcony'] > 1) $balcony .= 'ов';
            $pdf->Cell(50, 10, '🪟 Балкон:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $balcony, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // Ремонт
        if (!empty($cian_data['repair'])) {
            $repair = $cian_data['repair'];
            if ($repair == 'euro') $repair = 'Евроремонт';
            $pdf->Cell(50, 10, '🔨 Ремонт:', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $repair, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(5);

        // ===== МЕТРО =====
        if (!empty($cian_data['metro'])) {
            $pdf->SetFont('freesans', 'B', 14);
            $pdf->Cell(0, 10, 'Станции метро', 0, 1);
            $pdf->Ln(2);

            $pdf->SetFont('freesans', '', 11);
            $fill = false;

            foreach ($cian_data['metro'] as $metro) {
                $pdf->Cell(50, 8, '🚇 ' . $metro['name'] . ':', 0, 0, 'L', $fill);
                $time = isset($metro['time']) ? $metro['time'] . ' мин.' : 'не указано';
                $pdf->Cell(0, 8, $time, 0, 1, 'L', $fill);
                $fill = !$fill;
            }
            $pdf->Ln(5);
        }

        // ===== ОПИСАНИЕ =====
        if (!empty($cian_data['description'])) {
            $pdf->SetFont('freesans', 'B', 14);
            $pdf->Cell(0, 10, 'Описание', 0, 1);
            $pdf->Ln(2);

            $pdf->SetFont('freesans', '', 11);
            $pdf->SetFillColor(250, 250, 250);

            $description = $cian_data['description'];
            $pdf->MultiCell(0, 6, $description, 0, 'L', true);
            $pdf->Ln(5);
        }

        // ===== МЕБЕЛЬ И ТЕХНИКА =====
        if (!empty($cian_data['amenities'])) {
            $pdf->SetFont('freesans', 'B', 14);
            $pdf->Cell(0, 10, 'В квартире есть', 0, 1);
            $pdf->Ln(2);

            $pdf->SetFont('freesans', '', 11);

            // Разбиваем на колонки
            $amenities = $cian_data['amenities'];
            $half = ceil(count($amenities) / 2);

            for ($i = 0; $i < $half; $i++) {
                $left = $amenities[$i] ?? '';
                $right = $amenities[$i + $half] ?? '';

                $pdf->Cell(70, 8, '✓ ' . $left, 0, 0, 'L');
                if (!empty($right)) {
                    $pdf->Cell(70, 8, '✓ ' . $right, 0, 1, 'L');
                } else {
                    $pdf->Ln(8);
                }
            }
            $pdf->Ln(5);
        }

        // ===== ФОТОГРАФИИ =====
        if (!empty($cian_data['photos'])) {
            $pdf->SetFont('freesans', 'B', 14);
            $pdf->Cell(0, 10, 'Фотографии', 0, 1);
            $pdf->Ln(2);

            $photos = array_slice($cian_data['photos'], 0, 6); // Максимум 6 фото

            for ($i = 0; $i < count($photos); $i++) {
                if ($i % 2 == 0 && $i > 0) {
                    $pdf->AddPage();
                }

                $x = $pdf->GetX() + ($i % 2) * 95;
                $y = $pdf->GetY();

                $photo_url = $photos[$i];
                $temp_file = $this->downloadPhoto($photo_url);

                if ($temp_file && file_exists($temp_file)) {
                    $pdf->Image($temp_file, $x, $y, 90, 60, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    unlink($temp_file);
                }

                if ($i % 2 == 1) {
                    $pdf->SetY($y + 65);
                }
            }
        }

        // ===== ПОДВАЛ =====
        $pdf->Ln(10);
        $pdf->SetFont('freesans', 'I', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(0, 4, 'Данные получены с сайта ЦИАН', 0, 1, 'C');
        $pdf->Cell(0, 4, 'Ссылка: ' . ($cian_data['url'] ?? ''), 0, 1, 'C');
        $pdf->Cell(0, 4, 'Презентация сгенерирована автоматически', 0, 1, 'C');

        // Сохраняем файл
        $filename = 'cian_' . date('Ymd_His') . '.pdf';
        $filepath = sys_get_temp_dir() . '/' . $filename;
        $pdf->Output($filepath, 'F');

        return $filepath;
    }

    private function downloadPhoto($url) {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

            $image_data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200 && $image_data) {
                $temp_file = tempnam(sys_get_temp_dir(), 'cian_photo_') . '.jpg';
                file_put_contents($temp_file, $image_data);
                return $temp_file;
            }
        } catch (Exception $e) {
            error_log("Download photo error: " . $e->getMessage());
        }

        return null;
    }
}