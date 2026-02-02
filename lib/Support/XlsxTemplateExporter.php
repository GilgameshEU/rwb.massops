<?php

namespace Rwb\Massops\Support;

use JetBrains\PhpStorm\NoReturn;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Экспортёр XLSX-шаблонов для импорта
 */
class XlsxTemplateExporter
{
    /**
     * Создаёт и отдаёт XLSX-шаблон для импорта
     *
     * @param array<string, string> $fields        Список полей (код => название)
     * @param string[]              $requiredCodes Коды обязательных полей
     * @param string                $filename      Имя файла для скачивания
     */
    #[NoReturn] public static function export(array $fields, array $requiredCodes, string $filename = 'import_template.xlsx'): void
    {
        $requiredMap = array_flip($requiredCodes);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Импорт');

        $col = 1;

        foreach ($fields as $code => $title) {
            $column = Coordinate::stringFromColumnIndex($col);
            $cell = $column . '1';

            $sheet->setCellValue($cell, $title);
            $sheet->getColumnDimension($column)->setAutoSize(true);

            if (isset($requiredMap[$code])) {
                self::applyRequiredStyle($sheet, $cell);
                self::addRequiredComment($sheet, $cell);
            }

            $col++;
        }

        self::output($spreadsheet, $filename);
    }

    /**
     * Применяет стиль для обязательного поля
     */
    private static function applyRequiredStyle(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FF9C0006'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFFFC7CE'],
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }

    /**
     * Добавляет комментарий к обязательному полю
     */
    private static function addRequiredComment(Worksheet $sheet, string $cell): void
    {
        $comment = $sheet->getComment($cell);
        $comment->getText()->createTextRun('Обязательное поле');
        $comment->setWidth('220px');
        $comment->setHeight('60px');
    }

    /**
     * Отдаёт XLSX-файл в браузер
     *
     * @todo Заменить die() на более чистый способ завершения response
     */
    #[NoReturn] private static function output(Spreadsheet $spreadsheet, string $filename): void
    {
        global $APPLICATION;

        while (ob_get_level()) {
            ob_end_clean();
        }

        $APPLICATION->RestartBuffer();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');

        die();
    }
}
