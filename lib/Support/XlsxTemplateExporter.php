<?php

namespace Rwb\Massops\Support;

use Bitrix\Main\Application;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Экспортёр XLSX-шаблонов для импорта
 */
class XlsxTemplateExporter
{
    /**
     * Дополнительные обязательные поля по типу сущности
     * (поля, которые не помечены как required в CRM, но обязательны для импорта)
     */
    private const EXTRA_REQUIRED_FIELDS = [
        'company' => ['TITLE'],
        'contact' => ['NAME'],
        'deal' => ['TITLE'],
    ];

    /**
     * Создаёт и отдаёт XLSX-шаблон для импорта
     *
     * @param array  $fieldsData  Полные данные о полях из getFieldsForTemplate()
     * @param string $entityType  Тип сущности (company, contact, deal)
     * @param string $filename    Имя файла для скачивания
     */
    public static function export(array $fieldsData, string $entityType, string $filename = 'import_template.xlsx'): void
    {
        $spreadsheet = new Spreadsheet();

        // Определяем обязательные поля
        $extraRequired = self::EXTRA_REQUIRED_FIELDS[$entityType] ?? [];
        $requiredFields = [];
        $optionalFields = [];

        foreach ($fieldsData as $code => $field) {
            $isRequired = $field['required'] || in_array($code, $extraRequired, true);
            $field['required'] = $isRequired;

            if ($isRequired) {
                $requiredFields[$code] = $field;
            } else {
                $optionalFields[$code] = $field;
            }
        }

        // Лист 1: Шаблон для импорта (только обязательные поля)
        $templateSheet = $spreadsheet->getActiveSheet();
        $templateSheet->setTitle('Импорт');
        self::fillTemplateSheet($templateSheet, $requiredFields);

        // Лист 2: Справочник полей
        $referenceSheet = $spreadsheet->createSheet();
        $referenceSheet->setTitle('Справочник полей');
        self::fillReferenceSheet($referenceSheet, $requiredFields, $optionalFields);

        self::output($spreadsheet, $filename);
    }

    /**
     * Заполняет лист шаблона для импорта
     */
    private static function fillTemplateSheet(Worksheet $sheet, array $requiredFields): void
    {
        $col = 1;

        foreach ($requiredFields as $code => $field) {
            $column = Coordinate::stringFromColumnIndex($col);
            $cell = $column . '1';

            $sheet->setCellValue($cell, $field['title']);
            $sheet->getColumnDimension($column)->setAutoSize(true);

            self::applyRequiredStyle($sheet, $cell);
            self::addRequiredComment($sheet, $cell);

            $col++;
        }
    }

    /**
     * Заполняет лист справочника полей
     */
    private static function fillReferenceSheet(Worksheet $sheet, array $requiredFields, array $optionalFields): void
    {
        // Заголовки
        $sheet->setCellValue('A1', 'Название поля');
        $sheet->setCellValue('B1', 'Тип данных');
        $sheet->setCellValue('C1', 'Допустимые значения');

        self::applyHeaderStyle($sheet, 'A1:C1');

        $row = 2;

        // Сначала обязательные поля
        foreach ($requiredFields as $code => $field) {
            self::writeFieldRow($sheet, $row, $field, true);
            $row++;
        }

        // Затем опциональные поля
        foreach ($optionalFields as $code => $field) {
            self::writeFieldRow($sheet, $row, $field, false);
            $row++;
        }

        // Настройка ширины колонок
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(60);

        // Включаем перенос текста для колонки со значениями
        $sheet->getStyle('C2:C' . ($row - 1))->getAlignment()->setWrapText(true);
    }

    /**
     * Записывает строку с информацией о поле
     */
    private static function writeFieldRow(Worksheet $sheet, int $row, array $field, bool $isRequired): void
    {
        $sheet->setCellValue('A' . $row, $field['title']);
        $sheet->setCellValue('B' . $row, $field['type']);

        // Допустимые значения для enum полей
        $enumText = '';
        if (!empty($field['enumValues'])) {
            $values = array_map(
                fn($item) => $item['value'],
                $field['enumValues']
            );
            $enumText = implode(', ', $values);
        }
        $sheet->setCellValue('C' . $row, $enumText);

        // Стиль для обязательных полей
        if ($isRequired) {
            self::applyRequiredRowStyle($sheet, $row);
        }
    }

    /**
     * Применяет стиль для обязательного поля (заголовок в шаблоне)
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
     * Применяет стиль для обязательной строки в справочнике
     */
    private static function applyRequiredRowStyle(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FF9C0006'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFFFC7CE'],
            ],
        ]);
    }

    /**
     * Применяет стиль заголовков
     */
    private static function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE0E0E0'],
            ],
            'borders' => [
                'bottom' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
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
