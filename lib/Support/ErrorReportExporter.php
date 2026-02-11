<?php

namespace Rwb\Massops\Support;

use Bitrix\Main\Application;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Экспортёр XLSX-отчёта с ошибками dry-run
 *
 * Выгружает исходные данные + колонку с ошибками,
 * строки с ошибками выделены красным.
 */
class ErrorReportExporter
{
    /**
     * Создаёт и отдаёт XLSX-файл с результатами проверки
     *
     * @param array $columns    Колонки грида [{id, name}, ...]
     * @param array $rows       Строки грида [{id, data: {COL_0: val, ...}}, ...]
     * @param array $errors     Ошибки по строкам {rowIndex: [{message}, ...], ...}
     * @param string $filename  Имя файла для скачивания
     */
    public static function export(
        array $columns,
        array $rows,
        array $errors,
        string $filename = 'import_errors.xlsx'
    ): void {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Результаты проверки');

        // Заголовки: оригинальные колонки + "Ошибки"
        $col = 1;
        foreach ($columns as $column) {
            $cellAddr = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cellAddr, $column['name']);
            $col++;
        }

        // Колонка "Ошибки"
        $errorColLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($errorColLetter . '1', 'Ошибки');

        // Стиль заголовков
        $headerRange = 'A1:' . $errorColLetter . '1';
        self::applyHeaderStyle($sheet, $headerRange);

        // Данные
        $rowNum = 2;
        foreach ($rows as $rowIndex => $row) {
            $col = 1;

            // Данные из грида
            foreach ($columns as $column) {
                $cellAddr = Coordinate::stringFromColumnIndex($col) . $rowNum;
                $value = $row['data'][$column['id']] ?? '';
                $sheet->setCellValue($cellAddr, $value);
                $col++;
            }

            // Колонка с ошибками
            $errorMessages = [];
            if (isset($errors[$rowIndex]) && !empty($errors[$rowIndex])) {
                foreach ($errors[$rowIndex] as $error) {
                    $errorMessages[] = $error['message'] ?? (string) $error;
                }
            }

            $errorCellAddr = $errorColLetter . $rowNum;
            $sheet->setCellValue($errorCellAddr, implode('; ', $errorMessages));

            // Подсветка строки с ошибками
            if (!empty($errorMessages)) {
                $rowRange = 'A' . $rowNum . ':' . $errorColLetter . $rowNum;
                self::applyErrorRowStyle($sheet, $rowRange);
            }

            $rowNum++;
        }

        // Авторазмер колонок
        foreach (range(1, $col) as $colIndex) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        self::output($spreadsheet, $filename);
    }

    /**
     * Стиль заголовков
     */
    private static function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE0E0E0'],
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);
    }

    /**
     * Стиль строки с ошибкой
     */
    private static function applyErrorRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFFFC7CE'],
            ],
        ]);
    }

    /**
     * Отдаёт XLSX-файл в браузер
     */
    private static function output(Spreadsheet $spreadsheet, string $filename): void
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

        Application::getInstance()->end();
    }
}
