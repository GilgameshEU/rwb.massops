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

        $col = 1;
        foreach ($columns as $column) {
            $cellAddr = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cellAddr, $column['name']);
            $col++;
        }

        $errorColLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($errorColLetter . '1', 'Ошибки');

        $headerRange = 'A1:' . $errorColLetter . '1';
        self::applyHeaderStyle($sheet, $headerRange);

        $rowNum = 2;
        foreach ($rows as $rowIndex => $row) {
            $col = 1;

            foreach ($columns as $column) {
                $cellAddr = Coordinate::stringFromColumnIndex($col) . $rowNum;
                $value = $row['data'][$column['id']] ?? '';
                $sheet->setCellValue($cellAddr, $value);
                $col++;
            }

            $errorMessages = [];
            $hasDuplicateError = false;

            if (isset($errors[$rowIndex]) && !empty($errors[$rowIndex])) {
                foreach ($errors[$rowIndex] as $error) {
                    $errorMessages[] = self::formatErrorMessage($error);
                    if (isset($error['type']) && $error['type'] === 'duplicate') {
                        $hasDuplicateError = true;
                    }
                }
            }

            $errorCellAddr = $errorColLetter . $rowNum;
            $sheet->setCellValue($errorCellAddr, implode('; ', $errorMessages));

            $rowRange = 'A' . $rowNum . ':' . $errorColLetter . $rowNum;
            if (!empty($errorMessages)) {
                if ($hasDuplicateError) {
                    self::applyDuplicateRowStyle($sheet, $rowRange);
                } else {
                    self::applyErrorRowStyle($sheet, $rowRange);
                }
            } else {
                self::applySuccessRowStyle($sheet, $rowRange);
            }

            $rowNum++;
        }

        foreach (range(1, $col) as $colIndex) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        self::output($spreadsheet, $filename);
    }

    /**
     * Форматирует сообщение об ошибке для XLSX-отчёта
     *
     * @param array $error Данные ошибки
     *
     * @return string
     */
    private static function formatErrorMessage(array $error): string
    {
        $code = $error['code'] ?? '';
        $context = $error['context'] ?? [];

        if ($code === 'DUPLICATE_IN_FILE') {
            $inn = $context['inn'] ?? '';
            $duplicateRows = $context['duplicateRows'] ?? [];
            if (!empty($duplicateRows)) {
                $rowsStr = implode(', ', $duplicateRows);
                return $inn
                    ? "Дубликат ИНН \"{$inn}\": совпадает со строками {$rowsStr}"
                    : "Дубликат ИНН: совпадает со строками {$rowsStr}";
            }
        }

        if ($code === 'DUPLICATE_IN_CRM') {
            $companyId = $context['existingCompanyId'] ?? '';
            $inn = $context['inn'] ?? '';
            if ($companyId) {
                return $inn
                    ? "Компания с ИНН \"{$inn}\" уже существует в CRM (ID: {$companyId})"
                    : "Компания с таким ИНН уже существует в CRM (ID: {$companyId})";
            }
        }

        return $error['message'] ?? (string)$error;
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
     * Стиль строки с ошибкой (красный)
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
     * Стиль строки с дублем (синий)
     */
    private static function applyDuplicateRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFDBEAFE'],
            ],
        ]);
    }

    /**
     * Стиль успешной строки (зелёный)
     */
    private static function applySuccessRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFD1FAE5'],
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
