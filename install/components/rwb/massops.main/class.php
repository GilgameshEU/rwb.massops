<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Rwb\Massops\Import\CompanyImportService;
use Rwb\Massops\Repository\CRM\CompanyRepository;

class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    private ?CompanyImportService $importService = null;
    private CompanyRepository $companyRepository;

    /**
     * @throws LoaderException
     */
    public function onPrepareComponentParams($arParams): array
    {
        if (!Loader::includeModule('rwb.massops')) {
            throw new \RuntimeException('Module rwb.massops not loaded');
        }

        $this->companyRepository = new CompanyRepository();

        return parent::onPrepareComponentParams($arParams);
    }

    public function configureActions(): array
    {
        return [
            'uploadFile' => ['prefilters' => []],
            'clear' => ['prefilters' => []],
            'downloadTemplate' => ['prefilters' => []],
            'importCompanies' => ['prefilters' => []],
        ];
    }

    public function executeComponent(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            ShowError('Доступ запрещён');

            return;
        }

        $this->arResult['COMPANY_FIELDS'] = $this->companyRepository->getFieldList();

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

    public function downloadTemplateAction(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new \Bitrix\Main\AccessDeniedException();
        }

        $fields = $this->companyRepository->getFieldList();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="company_import_template.csv"');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, array_values($fields), ';');

        fclose($output);
        die();
    }

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
        $rows = $this->getImportService()->parseFile($file['tmp_name'], $ext);

        if (count($rows) < 1) {
            throw new \RuntimeException('Файл пустой');
        }

        $headerRow = array_shift($rows);
        $columns = [];

        foreach ($headerRow as $index => $name) {
            $columns[] = [
                'id' => 'COL_' . $index,
                'name' => (string) $name,
                'default' => true,
            ];
        }

        $gridRows = [];

        foreach ($rows as $rowIndex => $row) {
            $data = [];

            foreach ($row as $cellIndex => $cellValue) {
                $data['COL_' . $cellIndex] = (string) $cellValue;
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
        $saved = $_SESSION['RWB_MASSOPS_RESULT'] ?? null;
        if (!$saved || empty($saved['ROWS'])) {
            throw new \RuntimeException('Нет данных для импорта');
        }

        $fieldCodes = array_keys($this->companyRepository->getFieldList());
        $success = 0;

        foreach ($saved['ROWS'] as $row) {
            $fields = [];
            $fm = [];

            foreach ($row['data'] as $col => $value) {
                $index = (int) str_replace('COL_', '', $col);
                $fieldCode = $fieldCodes[$index] ?? null;

                if (!$fieldCode || $value === '') {
                    continue;
                }

                if (in_array($fieldCode, ['PHONE', 'EMAIL'], true)) {
                    $fm[$fieldCode] = $value;
                } else {
                    $fields[$fieldCode] = $value;
                }
            }

            if (empty($fields['TITLE'])) {
                continue;
            }

            $result = $this->companyRepository->add($fields, $fm);
            if ($result->isSuccess()) {
                $success++;
            }
        }

        return [
            'success' => true,
            'added' => $success,
        ];
    }

    public function clearAction(): array
    {
        unset($_SESSION['RWB_MASSOPS_RESULT']);

        return ['status' => 'success'];
    }

    protected function getImportService(): CompanyImportService
    {
        if ($this->importService === null) {
            $this->importService = new CompanyImportService();
        }

        return $this->importService;
    }
}
