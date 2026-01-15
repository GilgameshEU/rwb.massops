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

        return $this->buildGrid($rows);
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

    /**
     * Подготовка данных для Bitrix UI Grid
     */
    protected function buildGrid(array $rows): array
    {
        if (empty($rows)) {
            throw new \RuntimeException('Файл пустой');
        }

        $headers = array_shift($rows);

        $columns = [];
        foreach ($headers as $key => $title) {
            $columns[] = [
                'id' => 'COL_' . $key,
                'name' => (string) $title,
                'sort' => 'COL_' . $key,
            ];
        }

        $gridRows = [];
        foreach ($rows as $i => $row) {
            $data = [];
            foreach ($row as $k => $value) {
                $data['COL_' . $k] = $value;
            }

            $gridRows[] = [
                'id' => $i,
                'data' => $data,
            ];
        }

        return [
            'GRID_ID' => 'RWB_CRM_COMPANY_IMPORT',
            'COLUMNS' => $columns,
            'ROWS' => $gridRows,
            'SHOW_ROW_CHECKBOXES' => true,
            'SHOW_TOTAL_COUNTER' => true,
            'TOTAL_ROWS_COUNT' => count($gridRows),
        ];
    }
}
