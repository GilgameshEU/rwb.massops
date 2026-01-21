<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Rwb\Massops\Component\Helper\GridDataConverter;
use Rwb\Massops\Component\Helper\SessionStorage;
use Rwb\Massops\Import\FieldValidator;
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

        $this->arResult['GRID_COLUMNS'] = SessionStorage::getColumns();
        $this->arResult['GRID_ROWS'] = SessionStorage::getRows();

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
        $parseResult = $this->importService->parseFile(
            $file['tmp_name'],
            $ext
        );

        // Проверяем ошибки парсинга
        if (!empty($parseResult['errors'])) {
            $errorMessages = array_map(
                fn($error) => $error->toArray(),
                $parseResult['errors']
            );
            return [
                'success' => false,
                'errors' => $errorMessages,
            ];
        }

        $rows = $parseResult['data'];

        $validator = new FieldValidator();
        $validationErrors = $validator->validate($rows, $this->companyRepository);

        // Проверяем ошибки валидации
        if (!empty($validationErrors)) {
            $errorMessages = array_map(
                fn($error) => $error->toArray(),
                $validationErrors
            );
            return [
                'success' => false,
                'errors' => $errorMessages,
            ];
        }

        $headerRow = array_shift($rows);
        $gridData = GridDataConverter::convertToGridFormat($rows, $headerRow);

        SessionStorage::save($gridData['columns'], $gridData['rows']);

        return ['total' => count($gridData['rows'])];
    }

    public function importCompaniesAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new \RuntimeException('Нет данных для импорта');
        }

        $result = $this->importService->import(
            SessionStorage::getRows()
        );

        // Конвертируем ошибки в формат для грида
        $gridErrors = [];
        foreach ($result['errors'] as $rowIndex => $rowErrors) {
            $gridErrors[$rowIndex] = array_map(
                fn($error) => $error->toArray(),
                $rowErrors
            );
        }

        return [
            'success' => true,
            'added' => $result['success'],
            'errors' => $gridErrors,
        ];
    }

    public function clearAction(): array
    {
        SessionStorage::clear();

        return ['status' => 'success'];
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
}
