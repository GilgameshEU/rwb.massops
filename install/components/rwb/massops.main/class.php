<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Rwb\Massops\Import\CompanyImportService;

class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    /**
     * Сервис импорта (ленивая инициализация)
     */
    private ?CompanyImportService $importService = null;

    /**
     * Описываем AJAX-действия
     */
    public function configureActions(): array
    {
        return [
            'uploadFile' => [
                'prefilters' => [],
            ],
        ];
    }

    /**
     * Основной вывод компонента
     */
    public function executeComponent()
    {
        if (!CurrentUser::get()->isAdmin()) {
            ShowError('Доступ запрещён');

            return;
        }

        // Получаем данные из сессии или ставим дефолтные пустые
        $saved = $_SESSION['RWB_MASSOPS_RESULT'] ?? [];
        $this->arResult['GRID_COLUMNS'] = $saved['COLUMNS'] ?? [
            [
                'id' => 'EMPTY',
                'name' => 'Файл не загружен',
                'default' => true,
            ],
        ];
        $this->arResult['GRID_ROWS'] = $saved['ROWS'] ?? [];

        $this->includeComponentTemplate();
    }

    /**
     * AJAX: загрузка и парсинг CSV/XLSX
     */
    public function uploadFileAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new \Bitrix\Main\AccessDeniedException();
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            throw new \RuntimeException('Поддерживаются только CSV и XLSX');
        }

        $rows = $this
            ->getImportService()
            ->parseFile($file['tmp_name'], $ext);

        if (empty($rows)) {
            throw new \RuntimeException('Файл пустой');
        }

        // 1. Извлекаем заголовки (первая строка)
        $headerRow = array_shift($rows);
        $columns = [];
        foreach ($headerRow as $index => $name) {
            $columns[] = [
                'id' => 'COL_' . $index,
                'name' => (string) $name,
                'default' => true,
            ];
        }

        // 2. Формируем данные строк
        $gridRows = [];
        foreach ($rows as $rowIndex => $row) {
            $data = [];
            foreach ($row as $cellIndex => $cellValue) {
                // Если значение массив (бывает в XLSX), берем первый элемент или пустую строку
                $data['COL_' . $cellIndex] = is_array($cellValue) ? implode(', ', $cellValue) : (string) $cellValue;
            }

            $gridRows[] = [
                'id' => 'row_' . $rowIndex,
                'data' => $data,
            ];
        }

        // Сохраняем всё в сессию
        $_SESSION['RWB_MASSOPS_RESULT'] = [
            'COLUMNS' => $columns,
            'ROWS' => $gridRows,
        ];

        return ['total' => count($gridRows)];
    }

    public function clearAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new \Bitrix\Main\AccessDeniedException();
        }

        unset($_SESSION['RWB_MASSOPS_RESULT']);

        return ['status' => 'success'];
    }

    /**
     * Ленивая загрузка сервиса импорта
     */
    protected function getImportService(): CompanyImportService
    {
        if ($this->importService === null) {
            if (!Loader::includeModule('rwb.massops')) {
                throw new \RuntimeException('Module rwb.massops not loaded');
            }

            $this->importService = new CompanyImportService();
        }

        return $this->importService;
    }
}
