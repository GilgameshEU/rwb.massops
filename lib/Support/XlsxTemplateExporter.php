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
use Rwb\Massops\Import\Parser\XlsxParser;

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
     * Обязательные UF-поля по XML_ID для каждого типа сущности
     */
    private const EXTRA_REQUIRED_UF_XML_IDS = [
        'company' => ['INN'],
    ];

    /**
     * Подсказки по допустимым значениям для каждого типа поля
     */
    private const TYPE_HINTS = [
        'Строка'              => 'Текстовое значение',
        'Текст'               => 'Текстовое значение (многострочный)',
        'Символ'              => 'Один символ (Y или N)',
        'Целое число'         => 'Целое число. Пример: 42',
        'Число'               => 'Число (допускается десятичная точка). Пример: 199.90',
        'Да/Нет'              => 'Y — да, N — нет',
        'Дата'                => 'Формат: ДД.ММ.ГГГГ. Пример: 25.12.2025',
        'Дата и время'        => 'Формат: ДД.ММ.ГГГГ ЧЧ:ММ:СС. Пример: 25.12.2025 14:30:00',
        'Пользователь'        => 'ID пользователя или Имя Фамилия. Пример: 1 или Иван Иванов',
        'Сотрудник'           => 'ID пользователя или Имя Фамилия. Пример: 1 или Иван Иванов',
        'Валюта'              => 'Код валюты. Пример: RUB, USD, EUR',
        'Компания'            => 'ID компании в CRM. Пример: 123',
        'Контакт'             => 'ID контакта в CRM. Пример: 456',
        'Сделка'              => 'ID сделки в CRM. Пример: 789',
        'Лид'                 => 'ID лида в CRM. Пример: 101',
        'Предложение'         => 'ID предложения в CRM',
        'Направление'         => 'ID направления сделки',
        'Сущность CRM'        => 'ID сущности CRM',
        'Местоположение'      => 'Текстовое описание местоположения',
        'Деньги'              => 'Сумма (число). Пример: 15000.00',
        'Ссылка'              => 'URL-адрес. Пример: https://example.com',
        'Адрес'               => 'Полный адрес текстом',
        'Элемент инфоблока'   => 'ID элемента или его название. Пример: 42 или Категория А',
        'Раздел инфоблока'    => 'ID раздела или его название. Пример: 10 или Раздел Б',
        'Привязка к CRM'      => 'ID сущности CRM',
        'Справочник CRM'      => 'Значение из справочника CRM',
        'Смарт-процесс'       => 'ID элемента смарт-процесса',
        'Товар'               => 'ID товара в каталоге CRM',
        'Мультиполе'          => 'Значение мультиполя',
    ];

    /**
     * Подсказки для конкретных полей (приоритет выше TYPE_HINTS)
     */
    private const FIELD_HINTS = [
        'PHONE' => 'Номер телефона в формате +7XXXXXXXXXX или 8XXXXXXXXXX. Несколько номеров через запятую',
        'EMAIL' => 'Email-адрес. Несколько адресов через запятую. Пример: user@mail.ru, info@company.com',
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

        $extraRequired = self::EXTRA_REQUIRED_FIELDS[$entityType] ?? [];
        $extraRequiredUf = self::getExtraRequiredUfCodes($entityType);
        $allExtraRequired = array_merge($extraRequired, $extraRequiredUf);

        $requiredFields = [];
        $optionalFields = [];

        foreach ($fieldsData as $code => $field) {
            $isRequired = $field['required'] || in_array($code, $allExtraRequired, true);
            $field['required'] = $isRequired;

            if ($isRequired) {
                $requiredFields[$code] = $field;
            } else {
                $optionalFields[$code] = $field;
            }
        }

        $templateSheet = $spreadsheet->getActiveSheet();
        $templateSheet->setTitle(XlsxParser::IMPORT_SHEET_NAME);
        self::fillTemplateSheet($templateSheet, $requiredFields);

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
        $sheet->setCellValue('A1', 'Название поля');
        $sheet->setCellValue('B1', 'Тип данных');
        $sheet->setCellValue('C1', 'Допустимые значения');

        self::applyHeaderStyle($sheet, 'A1:C1');

        $row = 2;

        foreach ($requiredFields as $code => $field) {
            self::writeFieldRow($sheet, $row, $field, true);
            $row++;
        }

        foreach ($optionalFields as $code => $field) {
            self::writeFieldRow($sheet, $row, $field, false);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(60);

        $sheet->getStyle('C2:C' . ($row - 1))->getAlignment()->setWrapText(true);
    }

    /**
     * Записывает строку с информацией о поле
     */
    private static function writeFieldRow(Worksheet $sheet, int $row, array $field, bool $isRequired): void
    {
        $sheet->setCellValue('A' . $row, $field['title']);
        $sheet->setCellValue('B' . $row, $field['type']);
        $sheet->setCellValue('C' . $row, self::getFieldHint($field));

        if ($isRequired) {
            self::applyRequiredRowStyle($sheet, $row);
        }
    }

    /**
     * Формирует подсказку по допустимым значениям для поля
     */
    private static function getFieldHint(array $field): string
    {
        $code = $field['code'] ?? '';
        $type = $field['type'] ?? '';
        $isMultiple = $field['multiple'] ?? false;

        if (isset(self::FIELD_HINTS[$code])) {
            return self::FIELD_HINTS[$code];
        }

        if (!empty($field['enumValues'])) {
            $values = array_map(fn($item) => $item['value'], $field['enumValues']);
            $hint = implode(', ', $values);

            if ($isMultiple) {
                $hint .= "\n\nНесколько значений через запятую";
            }

            return $hint;
        }

        $hint = self::TYPE_HINTS[$type] ?? '';

        if ($hint !== '' && $isMultiple) {
            $hint .= ". Несколько значений через запятую";
        }

        return $hint;
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
     * Возвращает коды UF-полей, которые обязательны по XML_ID
     */
    private static function getExtraRequiredUfCodes(string $entityType): array
    {
        $xmlIds = self::EXTRA_REQUIRED_UF_XML_IDS[$entityType] ?? [];

        if (empty($xmlIds)) {
            return [];
        }

        $entityId = match ($entityType) {
            'company' => 'CRM_COMPANY',
            'contact' => 'CRM_CONTACT',
            'deal' => 'CRM_DEAL',
            default => null,
        };

        if (!$entityId) {
            return [];
        }

        $codes = [];
        foreach ($xmlIds as $xmlId) {
            $code = UserFieldHelper::getFieldCodeByXmlId($entityId, $xmlId);
            if ($code) {
                $codes[] = $code;
            }
        }

        return $codes;
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
