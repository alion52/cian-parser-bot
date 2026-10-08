<?php
// Сначала установите TCPDF через composer
// composer require tecnickcom/tcpdf

require_once 'vendor/tecnickcom/tcpdf/tcpdf.php';

class PDFGenerator {

    public function generate($params, $estimate) {
        // Создаем новый PDF документ
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Настройки документа
        $pdf->SetCreator('Оценщик недвижимости');
        $pdf->SetAuthor('Бот');
        $pdf->SetTitle('Отчет об оценке квартиры');
        $pdf->SetSubject('Оценка недвижимости');

        // Убираем колонтитулы
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Добавляем страницу
        $pdf->AddPage();

        // Заголовок
        $pdf->SetFont('freesans', 'B', 20);
        $pdf->SetTextColor(0, 84, 170); // синий
        $pdf->Cell(0, 20, 'ОТЧЕТ ОБ ОЦЕНКЕ НЕДВИЖИМОСТИ', 0, 1, 'C');
        $pdf->Ln(5);

        // Дата
        $pdf->SetFont('freesans', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, 'Дата оценки: ' . date('d.m.Y'), 0, 1, 'R');
        $pdf->Ln(10);

        // Параметры квартиры
        $pdf->SetFont('freesans', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 10, 'Параметры объекта:', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('freesans', '', 12);
        $pdf->SetFillColor(245, 245, 245);

        $data = [
            'Город' => $params['city'] ?? 'Москва',
            'Жилой комплекс' => $params['complex'] ?? 'Не указан',
            'Адрес' => $params['address'] ?? 'Не указан',
            'Площадь' => isset($params['area']) ? $params['area'] . ' м²' : 'Не указана',
            'Комнат' => $params['rooms'] ?? 'Не указано',
            'Этаж' => $params['floor'] ?? 'Не указан',
            'Ремонт' => $params['renovation'] ?? 'Не указан'
        ];

        $fill = false;
        foreach ($data as $label => $value) {
            $pdf->Cell(50, 10, $label . ':', 0, 0, 'L', $fill);
            $pdf->Cell(0, 10, $value, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(10);

        // Результаты оценки
        $pdf->SetFont('freesans', 'B', 14);
        $pdf->Cell(0, 10, 'Результаты оценки:', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('freesans', '', 12);

        $pdf->SetFillColor(230, 243, 255); // светло-голубой

        // Форматируем цены
        $min_price = number_format($estimate['min_price'], 0, '.', ' ');
        $max_price = number_format($estimate['max_price'], 0, '.', ' ');
        $avg_price = number_format($estimate['avg_price'], 0, '.', ' ');
        $price_per_m2 = number_format($estimate['price_per_m2'], 0, '.', ' ');

        $results = [
            'Минимальная цена' => $min_price . ' руб',
            'Максимальная цена' => $max_price . ' руб',
            'Средняя цена' => $avg_price . ' руб',
            'Цена за м²' => $price_per_m2 . ' руб',
            'Надежность оценки' => $this->getConfidenceText($estimate['confidence'])
        ];

        $fill = true;
        foreach ($results as $label => $value) {
            $pdf->Cell(60, 12, $label . ':', 0, 0, 'L', $fill);
            $pdf->Cell(0, 12, $value, 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(15);

        // Обоснование
        $pdf->SetFont('freesans', 'B', 12);
        $pdf->Cell(0, 10, 'Обоснование оценки:', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('freesans', '', 10);
        $pdf->MultiCell(0, 6, $estimate['reasoning'], 0, 'L');

        // Сохраняем файл
        $filename = 'estimate_' . date('Ymd_His') . '.pdf';
        $filepath = __DIR__ . '/' . $filename;
        $pdf->Output($filepath, 'F');

        return $filepath;
    }

    private function getConfidenceText($confidence) {
        switch ($confidence) {
            case 'high': return 'Высокая';
            case 'medium': return 'Средняя';
            case 'low': return 'Низкая';
            default: return 'Средняя';
        }
    }
}