<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Rwb\Massops\Import\Service\CompanyImport;
use Rwb\Massops\Repository\CRM\Company;

class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    private CompanyImport $importService;
    private Company $companyRepository;

    public function onPrepareComponentParams($arParams): array
    {
        if (!Loader::includeModule('rwb.massops')) {
            throw new \RuntimeException('Module rwb.massops not loaded');
        }

        $this->companyRepository = new Company();
        $this->importService = new CompanyImport(
            $this->companyRepository
        );

        return parent::onPrepareComponentParams($arParams);
    }

    public function configureActions(): array
    {
        return [
            'uploadFile' => ['prefilters' => []],
            'downloadTemplate' => ['prefilters' => []],
            'importCompanies' => ['prefilters' => []],
            'clear' => ['prefilters' => []],
        ];
    }

    public function executeComponent(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            ShowError('Доступ запрещён');

            return;
        }

        $this->arResult['COMPANY_FIELDS'] =
            $this->companyRepository->getFieldList();

        $saved = $_SESSION['RWB_MASSOPS_RESULT'] ?? [];

        $this->arResult['GRID_COLUMNS'] =
            $saved['COLUMNS'] ?? [];

        $this->arResult['GRID_ROWS'] =
            $saved['ROWS'] ?? [];

        $this->includeComponentTemplate();
    }

    public function uploadFileAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $rows = $this->importService->parseFile(
            $file['tmp_name'],
            $ext
        );

        $headerRow = array_shift($rows);
        $columns = [];

        foreach ($headerRow as $i => $name) {
            $columns[] = [
                'id' => 'COL_' . $i,
                'name' => (string) $name,
                'default' => true,
            ];
        }

        $gridRows = [];

        foreach ($rows as $rowIndex => $row) {
            $data = [];

            foreach ($row as $cellIndex => $value) {
                $data['COL_' . $cellIndex] = (string) $value;
            }

            $gridRows[] = [
                'id' => 'row_' . $rowIndex,
                'data' => $data,
            ];
        }

        $_SESSION['RWB_MASSOPS_RESULT'] = [
            'COLUMNS' => $columns,
            'ROWS' => $gridRows,
        ];

        return ['total' => count($gridRows)];
    }

    public function importCompaniesAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $saved = $_SESSION['RWB_MASSOPS_RESULT'] ?? null;
        if (!$saved || empty($saved['ROWS'])) {
            throw new \RuntimeException('Нет данных для импорта');
        }

        $result = $this->importService->import(
            $saved['ROWS']
        );

        return [
            'success' => true,
            'added' => $result['success'],
            'errors' => $result['errors'],
        ];
    }

    public function clearAction(): array
    {
        unset($_SESSION['RWB_MASSOPS_RESULT']);

        return ['status' => 'success'];
    }
}
