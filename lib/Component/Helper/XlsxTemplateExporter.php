<?php

namespace Rwb\Massops\Component\Helper;

use JetBrains\PhpStorm\NoReturn;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class XlsxTemplateExporter
{
    /**
     * Создаёт и отдаёт XLSX-шаблон для импорта
     *
     * В первой строке формируются заголовки полей.
     * Обязательные поля подсвечиваются цветом и помечаются комментарием.
     *
     * @param array<string, string> $fields
     *        Список полей в формате: код => название
     *
     * @param string[] $requiredCodes
     *        Список кодов обязательных полей
     */
    #[NoReturn] public static function export(array $fields, array $requiredCodes): void
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

        self::output($spreadsheet, 'company_import_template.xlsx');
    }

    /**
     * Применяет стиль для обязательного поля
     *
     * @param Worksheet $sheet Рабочий лист
     * @param string $cell     Адрес ячейки (например: A1)
     */
    protected static function applyRequiredStyle(Worksheet $sheet, string $cell): void
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
     *
     * @param Worksheet $sheet Рабочий лист
     * @param string $cell     Адрес ячейки
     */
    protected static function addRequiredComment(Worksheet $sheet, string $cell): void
    {
        $comment = $sheet->getComment($cell);
        $comment->getText()->createTextRun('Обязательное поле');
        $comment->setWidth('220px');
        $comment->setHeight('60px');
    }

    /**
     * Отдаёт XLSX-файл в браузер
     *
     * Полностью очищает буферы вывода и завершает выполнение скрипта.
     *
     * @param Spreadsheet $spreadsheet Объект таблицы
     * @param string $filename         Имя файла для скачивания
     */
    #[NoReturn] protected static function output(Spreadsheet $spreadsheet, string $filename): void
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
