<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Rwb\Massops\Import\CompanyImportService;
use Rwb\Massops\Import\ImportRowNormalizer;
use Rwb\Massops\Import\FieldValidator;
use Rwb\Massops\Repository\CRM\CompanyRepository;

class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    private ?CompanyImportService $importService = null;
    private CompanyRepository $companyRepository;

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

        $this->arResult['COMPANY_FIELDS'] =
            $this->companyRepository->getFieldList();

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
            throw new AccessDeniedException();
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
            throw new AccessDeniedException();
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $rows = $this->getImportService()->parseFile(
            $file['tmp_name'],
            $ext
        );

        if (count($rows) < 1) {
            throw new \RuntimeException('Файл пустой');
        }

        /**
         * 🔴 ВАЖНО:
         * Валидируем файл ЗДЕСЬ, ДО сохранения в сессию
         */
        $validator = new FieldValidator();
        $validator->validate($rows, $this->companyRepository);

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

    /**
     * @throws ArgumentException
     */


    public function importCompaniesAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new \Bitrix\Main\AccessDeniedException();
        }

        $saved = $_SESSION['RWB_MASSOPS_RESULT'] ?? null;
        if (!$saved || empty($saved['ROWS'])) {
            throw new \RuntimeException('Нет данных для импорта');
        }

        // 1️⃣ Коды полей CRM (TITLE, PHONE, EMAIL, UF_*)
        $fieldCodes = array_keys(
            $this->companyRepository->getFieldList()
        );

        // 2️⃣ НОРМАЛИЗАТОР (ВОТ ОН)
        $normalizer = new ImportRowNormalizer();

        $success = 0;
        $errors = [];

        foreach ($saved['ROWS'] as $rowIndex => $row) {
            // 3️⃣ ВОТ ЗДЕСЬ ВЫЗОВ normalize()
            [$fields, $uf, $fm] = $normalizer->normalize(
                array_values($row['data']), // значения строки CSV/XLSX
                $fieldCodes                  // соответствие колонок → CRM
            );

            // обязательное поле
            if (empty($fields['TITLE'])) {
                continue;
            }

            // 4️⃣ D7-сохранение
            $result = $this->companyRepository->add(
                $fields,
                $uf,
                $fm
            );

            if ($result->isSuccess()) {
                $success++;
            } else {
                $errors[$rowIndex] = $result->getErrorMessages();
            }
        }

        return [
            'success' => true,
            'added' => $success,
            'errors' => $errors,
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
