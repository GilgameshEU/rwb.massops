<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Rwb\Massops\Component\Helper\GridDataConverter;
use Rwb\Massops\Component\Helper\SessionStorage;
use Rwb\Massops\Component\Helper\XlsxTemplateExporter;
use Rwb\Massops\Import\FieldValidator;
use Rwb\Massops\Import\Service\CompanyImport;
use Rwb\Massops\Repository\CRM\Company;

/**
 * Основной компонент массового импорта компаний
 */
class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    private CompanyImport $importService;
    private Company $companyRepository;

    /**
     * Подготавливает параметры компонента и инициализирует сервисы
     *
     * @param array $arParams
     *
     * @return array
     * @throws LoaderException
     */
    public function onPrepareComponentParams($arParams): array
    {
        if (!Loader::includeModule('rwb.massops')) {
            throw new RuntimeException('Module rwb.massops not loaded');
        }

        $this->companyRepository = new Company();
        $this->importService = new CompanyImport(
            $this->companyRepository
        );

        return parent::onPrepareComponentParams($arParams);
    }

    /**
     * Конфигурация AJAX-действий компонента
     *
     * @return array
     */
    public function configureActions(): array
    {
        return [
            'uploadFile' => ['prefilters' => []],
            'downloadXlsxTemplate' => ['prefilters' => []],
            'importCompanies' => ['prefilters' => []],
            'dryRunImport' => ['prefilters' => []],
            'clear' => ['prefilters' => []],
        ];
    }

    /**
     * Основной метод выполнения компонента
     *
     */
    public function executeComponent(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            ShowError('Доступ запрещён');

            return;
        }

        $this->arResult['GRID_COLUMNS'] = SessionStorage::getColumns();
        $this->arResult['GRID_ROWS'] = SessionStorage::getRows();

        $this->includeComponentTemplate();
    }

    /**
     * Загружает и парсит файл импорта
     *
     * @return array
     * @throws LoaderException
     * @throws AccessDeniedException
     */
    public function uploadFileAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $parseResult = $this->importService->parseFile(
            $file['tmp_name'],
            $ext
        );

        if (!empty($parseResult['errors'])) {
            return [
                'success' => false,
                'errors' => array_map(
                    fn($error) => $error->toArray(),
                    $parseResult['errors']
                ),
            ];
        }

        $rows = $parseResult['data'];

        $validator = new FieldValidator();
        $validationErrors = $validator->validate(
            $rows,
            $this->companyRepository
        );

        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'errors' => array_map(
                    fn($error) => $error->toArray(),
                    $validationErrors
                ),
            ];
        }

        $headerRow = array_shift($rows);
        $gridData = GridDataConverter::convertToGridFormat(
            $rows,
            $headerRow
        );

        SessionStorage::save(
            $gridData['columns'],
            $gridData['rows']
        );

        return [
            'total' => count($gridData['rows']),
        ];
    }

    /**
     * Возвращает маппинг кодов полей CRM на ID колонок грида
     *
     * @return array<string, string> ['TITLE' => 'COL_0', 'PHONE' => 'COL_1', ...]
     * @throws LoaderException
     */
    private function getFieldToColumnMapping(): array
    {
        $fieldCodes = array_keys($this->companyRepository->getFieldList());
        $mapping = [];

        foreach ($fieldCodes as $index => $code) {
            $mapping[$code] = 'COL_' . $index;
        }

        return $mapping;
    }

    /**
     * Выполняет импорт компаний из сохранённых данных
     *
     * @return array
     * @throws AccessDeniedException|InvalidOperationException|LoaderException
     */
    public function importCompaniesAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }

        try {
            $result = $this->importService->import(
                SessionStorage::getRows()
            );
        } catch (ArgumentException|LoaderException $e) {
        }

        $gridErrors = [];
        foreach ($result['errors'] as $rowIndex => $rowErrors) {
            $gridErrors[$rowIndex] = array_map(
                fn($error) => $error->toArray(),
                $rowErrors
            );
        }

        $addedDetails = [];
        if (isset($result['added'])) {
            foreach ($result['added'] as $rowIndex => $item) {
                $addedDetails[$rowIndex] = $item;
            }
        }

        return [
            'success' => true,
            'added' => $result['success'],
            'errors' => $gridErrors,
            'addedDetails' => $addedDetails,
            'fieldToColumn' => $this->getFieldToColumnMapping(),
        ];
    }

    /**
     * Выполняет dry run импорта компаний (симуляция без сохранения)
     *
     * @return array
     * @throws AccessDeniedException|InvalidOperationException|LoaderException
     */
    public function dryRunImportAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }

        try {
            $result = $this->importService->dryRun(
                SessionStorage::getRows()
            );
        } catch (ArgumentException|LoaderException $e) {
        }

        $gridErrors = [];
        foreach ($result['errors'] as $rowIndex => $rowErrors) {
            $gridErrors[$rowIndex] = array_map(
                fn($error) => $error->toArray(),
                $rowErrors
            );
        }

        $wouldBeAdded = [];
        foreach ($result['wouldBeAdded'] as $rowIndex => $item) {
            $wouldBeAdded[$rowIndex] = $item;
        }

        return [
            'success' => true,
            'wouldBeAdded' => $result['success'],
            'errors' => $gridErrors,
            'wouldBeAddedDetails' => $wouldBeAdded,
            'fieldToColumn' => $this->getFieldToColumnMapping(),
        ];
    }

    /**
     * Очищает данные импорта из сессии
     *
     * @return array
     */
    public function clearAction(): array
    {
        SessionStorage::clear();

        return ['status' => 'success'];
    }

    /**
     * Скачивает CSV-шаблон для импорта компаний
     *
     * @throws AccessDeniedException|LoaderException
     */
    public function downloadCsvTemplateAction(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        global $APPLICATION;
        $APPLICATION->restartBuffer();

        $fields = $this->companyRepository->getFieldList();

        header('Content-Type: text/csv; charset=windows-1251');
        header('Content-Disposition: attachment; filename="company_import_template.csv"');
        header('Pragma: public');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');

        // ❗ BOM НЕ нужен для CP1251
        $row = array_map(
            static fn($value) => iconv('UTF-8', 'Windows-1251//TRANSLIT', $value),
            array_values($fields)
        );

        fputcsv($output, $row, ';');
        fclose($output);

        die();
    }

    /**
     * Скачивает XLSX-шаблон для импорта компаний
     *
     * @throws AccessDeniedException
     * @throws LoaderException
     */
    public function downloadXlsxTemplateAction(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        XlsxTemplateExporter::export(
            $this->companyRepository->getFieldList(),
            $this->companyRepository->getRequiredFieldCodes()
        );
    }
}
