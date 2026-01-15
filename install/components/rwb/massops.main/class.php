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

        if (empty($rows)) {
            throw new \RuntimeException('Файл пустой');
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
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

    protected function renderGrid(array $rows): string
    {
        return '';
    }
}
