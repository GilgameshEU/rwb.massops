<?php

namespace Rwb\Massops\Support;

use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Экспортёр XLSX-отчёта по завершённой задаче импорта
 *
 * Формат: лист «Сводка» + лист «Результаты импорта»
 * (повторяет структуру исходного файла + колонка ID + колонка Ошибки)
 */
class StatsReportExporter
{
    /**
     * Создаёт и отдаёт XLSX-файл с результатами импорта
     *
     * @param array  $job         Данные задачи из ImportJobTable (включая IMPORT_DATA, IMPORT_OPTIONS)
     * @param string $entityTitle Название типа сущности
     * @param string $filename    Имя файла для скачивания
     */
    public static function export(array $job, string $entityTitle, string $filename, array $fieldLabels = []): void
    {
        $spreadsheet = new Spreadsheet();

        $options = !empty($job['IMPORT_OPTIONS']) ? (json_decode($job['IMPORT_OPTIONS'], true) ?? []) : [];
        $columns = $options['columns'] ?? [];

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Сводка');
        self::fillSummarySheet($summarySheet, $job, $entityTitle, $options);

        $resultsSheet = $spreadsheet->createSheet();
        $resultsSheet->setTitle('Результаты импорта');
        self::fillResultsSheet($resultsSheet, $job, $columns, $fieldLabels);

        self::output($spreadsheet, $filename);
    }

    /**
     * Заполняет лист со сводной информацией
     */
    private static function fillSummarySheet(Worksheet $sheet, array $job, string $entityTitle, array $options): void
    {
        $userName = self::getUserName((int) $job['USER_ID']);
        $createCabinets = !empty($options['createCabinets']) ? 'Да' : 'Нет';

        $data = [
            ['Параметр', 'Значение'],
            ['Пользователь', $userName],
            ['Тип сущности', $entityTitle],
            ['Создание кабинетов', $createCabinets],
            ['Статус', self::getStatusLabel($job['STATUS'])],
            ['Всего строк', $job['TOTAL_ROWS']],
            ['Обработано', $job['PROCESSED_ROWS']],
            ['Успешно добавлено', $job['SUCCESS_COUNT']],
            ['Ошибок', $job['ERROR_COUNT']],
            ['Создано', $job['CREATED_AT'] ? $job['CREATED_AT']->toString() : '—'],
            ['Начало обработки', $job['STARTED_AT'] ? $job['STARTED_AT']->toString() : '—'],
            ['Завершено', $job['FINISHED_AT'] ? $job['FINISHED_AT']->toString() : '—'],
        ];

        $row = 1;
        foreach ($data as $rowData) {
            $sheet->setCellValue('A' . $row, $rowData[0]);
            $sheet->setCellValue('B' . $row, $rowData[1]);
            $row++;
        }

        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE0E0E0'],
            ],
        ]);

        $sheet->getStyle('A1:A' . ($row - 1))->applyFromArray([
            'font' => ['bold' => true],
        ]);

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
    }

    /**
     * Заполняет лист с результатами импорта
     *
     * Колонки: ID | CID | (все колонки исходного файла) | Ошибки
     */
    private static function fillResultsSheet(Worksheet $sheet, array $job, array $columns, array $fieldLabels = []): void
    {
        $allRows = !empty($job['IMPORT_DATA']) ? (json_decode($job['IMPORT_DATA'], true) ?? []) : [];
        $createdIds = !empty($job['CREATED_IDS']) ? (json_decode($job['CREATED_IDS'], true) ?? []) : [];
        $errors = !empty($job['ERRORS_DATA']) ? (json_decode($job['ERRORS_DATA'], true) ?? []) : [];

        if (!is_array($allRows)) {
            $allRows = [];
        }
        if (!is_array($createdIds)) {
            $createdIds = [];
        }
        if (!is_array($errors)) {
            $errors = [];
        }

        // Убираем системные ошибки из рядовых
        unset($errors['__exception__']);

        // === Заголовки ===
        $col = 1;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', 'ID');
        $col++;

        // Колонка CID — показываем только если хотя бы у одной записи есть CID
        $hasCid = false;
        foreach ($createdIds as $entry) {
            if (is_array($entry) && !empty($entry['cid'])) {
                $hasCid = true;
                break;
            }
        }

        $cidColIndex = null;
        if ($hasCid) {
            $cidColIndex = $col;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', 'CID');
            $col++;
        }

        foreach ($columns as $column) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $column['name']);
            $col++;
        }

        $errorColIndex = $col;
        $errorColLetter = Coordinate::stringFromColumnIndex($errorColIndex);
        $sheet->setCellValue($errorColLetter . '1', 'Ошибки');

        $headerRange = 'A1:' . $errorColLetter . '1';
        self::applyHeaderStyle($sheet, $headerRange);

        // === Данные ===
        $rowNum = 2;
        $allRowsIndexed = array_values($allRows);

        foreach ($allRowsIndexed as $rowIndex => $row) {
            $col = 1;

            // Колонка ID — поддерживаем оба формата: int (старый) и array ['id'=>...,'cid'=>...] (новый)
            $entry = $createdIds[$rowIndex] ?? null;
            $entityId = is_array($entry) ? ($entry['id'] ?? null) : $entry;
            $rowCid = is_array($entry) ? ($entry['cid'] ?? null) : null;

            $idCellAddr = Coordinate::stringFromColumnIndex($col) . $rowNum;
            if ($entityId) {
                $sheet->setCellValue($idCellAddr, $entityId);
            }
            $col++;

            // Колонка CID
            if ($cidColIndex !== null) {
                $cidCellAddr = Coordinate::stringFromColumnIndex($col) . $rowNum;
                if ($rowCid !== null) {
                    $sheet->setCellValue($cidCellAddr, $rowCid);
                }
                $col++;
            }

            // Колонки данных
            foreach ($columns as $column) {
                $cellAddr = Coordinate::stringFromColumnIndex($col) . $rowNum;
                $value = $row['data'][$column['id']] ?? '';
                $sheet->setCellValue($cellAddr, $value);
                $col++;
            }

            // Колонка ошибок
            $errorMessages = [];
            if (isset($errors[$rowIndex]) && !empty($errors[$rowIndex])) {
                foreach ($errors[$rowIndex] as $error) {
                    $errorMessages[] = self::formatErrorMessage($error, $fieldLabels);
                }
            }

            $errorCellAddr = $errorColLetter . $rowNum;
            $sheet->setCellValue($errorCellAddr, implode('; ', $errorMessages));

            // Стили строки
            $rowRange = 'A' . $rowNum . ':' . $errorColLetter . $rowNum;
            if (!empty($errorMessages)) {
                self::applyErrorRowStyle($sheet, $rowRange);
            } else {
                self::applySuccessRowStyle($sheet, $rowRange);
            }

            $rowNum++;
        }

        // Автоширина колонок
        for ($colIndex = 1; $colIndex <= $errorColIndex; $colIndex++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    /**
     * Форматирует сообщение об ошибке
     */
    private static function formatErrorMessage($error, array $fieldLabels = []): string
    {
        if ($error instanceof \Rwb\Massops\Import\ImportError) {
            $message = $error->message;
            if ($error->field !== null && isset($fieldLabels[$error->field])) {
                return '[' . $fieldLabels[$error->field] . '] ' . $message;
            }
            return $message;
        }

        if (!is_array($error)) {
            return (string) $error;
        }

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

        $message = $error['message'] ?? (string) $error;
        $field = $error['field'] ?? '';
        if ($field !== '' && isset($fieldLabels[$field])) {
            return '[' . $fieldLabels[$field] . '] ' . $message;
        }

        return $message;
    }

    /**
     * Возвращает имя пользователя по ID
     */
    private static function getUserName(int $userId): string
    {
        if ($userId <= 0) {
            return '—';
        }

        $user = UserTable::getById($userId)->fetch();

        if (!$user) {
            return "ID: $userId";
        }

        $parts = array_filter([
            $user['LAST_NAME'],
            $user['NAME'],
        ]);

        if (empty($parts)) {
            return $user['LOGIN'] ?: "ID: $userId";
        }

        return implode(' ', $parts) . ' (' . $user['LOGIN'] . ')';
    }

    /**
     * Возвращает текстовый статус
     */
    private static function getStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'Ожидание',
            'processing' => 'В процессе',
            'completed' => 'Завершён',
            'error' => 'Ошибка',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Стиль заголовков
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
