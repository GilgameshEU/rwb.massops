<?php

namespace Rwb\Massops\Support;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Экспортёр XLSX-отчёта по завершённой задаче импорта
 */
class StatsReportExporter
{
    /**
     * Создаёт и отдаёт XLSX-файл с результатами импорта
     *
     * @param array  $job         Данные задачи из ImportJobTable
     * @param string $entityTitle Название типа сущности
     * @param string $filename    Имя файла для скачивания
     */
    public static function export(array $job, string $entityTitle, string $filename): void
    {
        $spreadsheet = new Spreadsheet();

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Сводка');
        self::fillSummarySheet($summarySheet, $job, $entityTitle);

        $createdIds = !empty($job['CREATED_IDS']) ? unserialize($job['CREATED_IDS']) : [];
        if (!empty($createdIds)) {
            $entitiesSheet = $spreadsheet->createSheet();
            $entitiesSheet->setTitle('Добавленные');
            self::fillEntitiesSheet($entitiesSheet, $createdIds, $job['ENTITY_TYPE']);
        }

        $errors = !empty($job['ERRORS_DATA']) ? unserialize($job['ERRORS_DATA']) : [];
        if (!empty($errors)) {
            $errorsSheet = $spreadsheet->createSheet();
            $errorsSheet->setTitle('Ошибки');
            self::fillErrorsSheet($errorsSheet, $errors);
        }

        self::output($spreadsheet, $filename);
    }

    /**
     * Заполняет лист со сводной информацией
     */
    private static function fillSummarySheet(Worksheet $sheet, array $job, string $entityTitle): void
    {
        $userName = self::getUserName((int) $job['USER_ID']);

        $data = [
            ['Параметр', 'Значение'],
            ['Пользователь', $userName],
            ['Тип сущности', $entityTitle],
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
     * Заполняет лист с добавленными сущностями (ID + название)
     */
    private static function fillEntitiesSheet(Worksheet $sheet, array $createdIds, string $entityType): void
    {
        $sheet->setCellValue('A1', '№');
        $sheet->setCellValue('B1', 'ID');
        $sheet->setCellValue('C1', 'Название');

        self::applyHeaderStyle($sheet, 'A1:C1');

        $titles = self::getEntityTitles($createdIds, $entityType);

        $row = 2;
        foreach ($createdIds as $index => $id) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $id);
            $sheet->setCellValue('C' . $row, $titles[$id] ?? '—');
            $row++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);
    }

    /**
     * Получает названия сущностей по их ID
     *
     * @param array $ids ID сущностей
     * @param string $entityType Тип сущности (company, contact)
     *
     * @return array<int, string> ID => название
     */
    private static function getEntityTitles(array $ids, string $entityType): array
    {
        if (empty($ids)) {
            return [];
        }

        Loader::requireModule('crm');

        $titles = [];

        switch ($entityType) {
            case 'company':
                $rsCompanies = \CCrmCompany::getListEx(
                    [],
                    ['@ID' => $ids, 'CHECK_PERMISSIONS' => 'N'],
                    false,
                    false,
                    ['ID', 'TITLE']
                );
                while ($company = $rsCompanies->fetch()) {
                    $titles[(int)$company['ID']] = $company['TITLE'] ?? '';
                }
                break;

            case 'contact':
                $rsContacts = \CCrmContact::getListEx(
                    [],
                    ['@ID' => $ids, 'CHECK_PERMISSIONS' => 'N'],
                    false,
                    false,
                    ['ID', 'NAME', 'LAST_NAME']
                );
                while ($contact = $rsContacts->fetch()) {
                    $fullName = trim(($contact['LAST_NAME'] ?? '') . ' ' . ($contact['NAME'] ?? ''));
                    $titles[(int)$contact['ID']] = $fullName ?: '—';
                }
                break;
        }

        return $titles;
    }

    /**
     * Заполняет лист с ошибками
     */
    private static function fillErrorsSheet(Worksheet $sheet, array $errors): void
    {
        $sheet->setCellValue('A1', 'Строка');
        $sheet->setCellValue('B1', 'Ошибка');

        self::applyHeaderStyle($sheet, 'A1:B1');

        ksort($errors, SORT_NUMERIC);

        $row = 2;
        foreach ($errors as $rowIndex => $rowErrors) {
            foreach ($rowErrors as $error) {
                $sheet->setCellValue('A' . $row, $rowIndex + 1);
                $message = $error instanceof \Rwb\Massops\Import\ImportError
                    ? $error->message
                    : ($error['message'] ?? (string) $error);
                $sheet->setCellValue('B' . $row, $message);
                $row++;
            }
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(80);
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
