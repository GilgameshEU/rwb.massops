<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\UI\PageNavigation;
use Rwb\Massops\EntityRegistry;
use Rwb\Massops\Import\FieldValidator;
use Rwb\Massops\Import\FileParser;
use Rwb\Massops\Service\ImportJobService;
use Rwb\Massops\Support\ErrorReportExporter;
use Rwb\Massops\Support\GridDataConverter;
use Rwb\Massops\Support\SessionStorage;
use Rwb\Massops\Support\StatsReportExporter;
use Rwb\Massops\Support\XlsxTemplateExporter;

/**
 * Основной компонент массовых операций CRM
 *
 * Тонкий контроллер: проверяет права, читает параметры запроса,
 * делегирует бизнес-логику сервисам и форматирует ответ.
 */
class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    private const GRID_ID = 'RWB_MASSOPS_GRID';
    private const GRID_DEFAULT_PAGE_SIZE = 50;
    private const GRID_ALLOWED_PAGE_SIZES = [20, 50, 100, 200, 500];

    /**
     * Подготавливает параметры компонента
     */
    public function onPrepareComponentParams($arParams): array
    {
        if (!Loader::includeModule('rwb.massops')) {
            throw new RuntimeException('Module rwb.massops not loaded');
        }

        return parent::onPrepareComponentParams($arParams);
    }

    /**
     * Конфигурация AJAX-действий компонента
     */
    public function configureActions(): array
    {
        return [
            'uploadFile' => ['prefilters' => []],
            'downloadXlsxTemplate' => ['prefilters' => []],
            'runImport' => ['prefilters' => []],
            'runDryRun' => ['prefilters' => []],
            'clear' => ['prefilters' => []],
            'startImport' => ['prefilters' => []],
            'getProgress' => ['prefilters' => []],
            'getStats' => ['prefilters' => []],
            'downloadErrorReport' => ['prefilters' => []],
            'downloadStatsReport' => ['prefilters' => []],
        ];
    }


    /**
     * Основной метод выполнения компонента
     */
    public function executeComponent(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            ShowError('Доступ запрещён');

            return;
        }

        $this->arResult['ENTITY_TYPES'] = EntityRegistry::getAllForUi();
        $this->arResult['CURRENT_ENTITY_TYPE'] = SessionStorage::getEntityType();
        $this->arResult['HAS_DATA'] = SessionStorage::hasData();
        $this->arResult['GRID_COLUMNS'] = SessionStorage::getColumns();

        $allRows = SessionStorage::getRows();
        $sort = $this->getGridSort();

        if (!empty($sort['by']) && !empty($allRows)) {
            $allRows = GridDataConverter::sortRows($allRows, $sort['by'], $sort['order']);
        }

        $totalRows = count($allRows);
        $minPageSize = min(self::GRID_ALLOWED_PAGE_SIZES);

        if ($totalRows > $minPageSize) {
            $pageSize = $this->getGridPageSize();

            $nav = new PageNavigation('rwb-grid-nav');
            $nav->allowAllRecords(false);
            $nav->setPageSize($pageSize);
            $nav->setRecordCount($totalRows);
            $nav->initFromUri();

            $offset = $nav->getOffset();
            $pageRows = array_slice($allRows, $offset, $pageSize);

            $this->arResult['GRID_NAV'] = $nav;
        } else {
            $pageRows = $allRows;
        }

        $this->arResult['GRID_ROWS'] = $pageRows;
        $this->arResult['GRID_SORT'] = $sort;
        $this->arResult['GRID_TOTAL_ROWS'] = $totalRows;

        $this->includeComponentTemplate();
    }


    /**
     * Загружает и парсит файл импорта
     */
    public function uploadFileAction(): array
    {
        $this->checkAdmin();

        $entityType = $this->resolveEntityType();
        $repository = EntityRegistry::createRepository($entityType);

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $parseResult = (new FileParser())->parse($file['tmp_name'], $ext);

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
            $repository,
            EntityRegistry::getTitle($entityType)
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
            $gridData['rows'],
            $entityType
        );

        return [
            'total' => count($gridData['rows']),
        ];
    }


    /**
     * Выполняет импорт из сохранённых данных
     */
    public function runImportAction(): array
    {
        $this->checkAdmin();
        $this->checkData();

        $entityType = $this->resolveEntityType();
        $importService = EntityRegistry::createImportService($entityType);
        $options = $this->buildImportOptions($entityType);

        try {
            $result = $importService->import(
                SessionStorage::getRows(),
                $options
            );
        } catch (ArgumentException|LoaderException $e) {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [['type' => 'system', 'code' => 'IMPORT_FAILED', 'message' => $e->getMessage()]],
            ];
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
            'errors' => $this->formatGridErrors($result['errors']),
            'addedDetails' => $addedDetails,
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     */
    public function runDryRunAction(): array
    {
        $this->checkAdmin();
        $this->checkData();

        $entityType = $this->resolveEntityType();
        $importService = EntityRegistry::createImportService($entityType);
        $options = $this->buildImportOptions($entityType);

        try {
            $result = $importService->dryRun(
                SessionStorage::getRows(),
                $options
            );
        } catch (ArgumentException|LoaderException $e) {
            return [
                'success' => false,
                'wouldBeAdded' => 0,
                'errors' => [['type' => 'system', 'code' => 'DRY_RUN_FAILED', 'message' => $e->getMessage()]],
            ];
        }

        $wouldBeAdded = [];
        foreach ($result['wouldBeAdded'] as $rowIndex => $item) {
            $wouldBeAdded[$rowIndex] = $item;
        }

        return [
            'success' => true,
            'wouldBeAdded' => $result['success'],
            'errors' => $this->formatGridErrors($result['errors']),
            'wouldBeAddedDetails' => $wouldBeAdded,
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
        ];
    }


    /**
     * Ставит импорт в очередь на фоновую обработку
     */
    public function startImportAction(): array
    {
        $this->checkAdmin();
        $this->checkData();

        $entityType = $this->resolveEntityType();
        $options = $this->buildImportOptions($entityType);

        $service = new ImportJobService();
        $jobId = $service->createJob(
            (int) CurrentUser::get()->getId(),
            $entityType,
            SessionStorage::getRows(),
            $options
        );

        return ['jobId' => $jobId];
    }

    /**
     * Возвращает прогресс задачи импорта
     */
    public function getProgressAction(): array
    {
        $this->checkAdmin();

        $service = new ImportJobService();

        return $service->getProgress(
            $this->getRequestInt('jobId'),
            (int) CurrentUser::get()->getId()
        );
    }


    /**
     * Возвращает статистику задач импорта с пагинацией
     */
    public function getStatsAction(): array
    {
        $this->checkAdmin();

        $page = max(1, $this->getRequestInt('page'));
        $service = new ImportJobService();

        return $service->getHistory($page);
    }


    /**
     * Очищает данные импорта из сессии
     */
    public function clearAction(): array
    {
        SessionStorage::clear();

        return ['status' => 'success'];
    }

    /**
     * Скачивает XLSX-шаблон для импорта
     */
    public function downloadXlsxTemplateAction(): void
    {
        $this->checkAdmin();

        $entityType = $this->resolveEntityType();
        $repository = EntityRegistry::createRepository($entityType);

        $filename = $entityType . '_import_template.xlsx';

        XlsxTemplateExporter::export(
            $repository->getFieldsForTemplate(),
            $entityType,
            $filename
        );
    }

    /**
     * Скачивает XLSX-отчёт с ошибками dry-run
     */
    public function downloadErrorReportAction(): void
    {
        $this->checkAdmin();
        $this->checkData();

        $errorsJson = $this->getRequestParam('errors');
        $errors = $errorsJson ? json_decode($errorsJson, true) : [];

        $columns = SessionStorage::getColumns();
        $rows = SessionStorage::getRows();
        $entityType = SessionStorage::getEntityType() ?: 'import';

        $filename = $entityType . '_errors_' . date('Y-m-d_H-i') . '.xlsx';

        ErrorReportExporter::export($columns, $rows, $errors, $filename);
    }

    /**
     * Скачивает XLSX-отчёт по задаче импорта из статистики
     */
    public function downloadStatsReportAction(): void
    {
        $this->checkAdmin();

        $jobId = $this->getRequestInt('jobId');

        if (!$jobId) {
            throw new RuntimeException('Job ID не указан');
        }

        $job = \Rwb\Massops\Queue\ImportJobTable::getById($jobId)->fetch();

        if (!$job) {
            throw new RuntimeException('Задача не найдена');
        }

        $entityTitles = [];
        foreach (EntityRegistry::getAllForUi() as $key => $config) {
            $entityTitles[$key] = $config['title'];
        }

        $entityTitle = $entityTitles[$job['ENTITY_TYPE']] ?? $job['ENTITY_TYPE'];
        $filename = $job['ENTITY_TYPE'] . '_import_report_' . $jobId . '.xlsx';

        StatsReportExporter::export($job, $entityTitle, $filename);
    }


    /**
     * Проверяет права администратора
     */
    private function checkAdmin(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Проверяет наличие данных в сессии
     */
    private function checkData(): void
    {
        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }
    }

    /**
     * Получает строковый параметр из POST или GET запроса
     */
    private function getRequestParam(string $key): ?string
    {
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

        return $request->getPost($key) ?? $request->get($key);
    }

    /**
     * Получает числовой параметр из запроса
     */
    private function getRequestInt(string $key): int
    {
        return (int) $this->getRequestParam($key);
    }

    /**
     * Определяет тип сущности из запроса или сессии
     */
    private function resolveEntityType(): string
    {
        $entityType = $this->getRequestParam('entityType')
            ?? SessionStorage::getEntityType();

        if (!$entityType || !EntityRegistry::has($entityType)) {
            throw new RuntimeException('Тип сущности не указан');
        }

        return $entityType;
    }

    /**
     * Собирает опции импорта из запроса
     */
    private function buildImportOptions(string $entityType): array
    {
        $options = [
            'columns' => SessionStorage::getColumns(),
        ];

        if ($entityType === 'company' && $this->getRequestParam('createCabinets') === 'Y') {
            $options['createCabinets'] = true;
        }

        return $options;
    }

    /**
     * Форматирует ошибки импорта для грида
     */
    private function formatGridErrors(array $errors): array
    {
        $gridErrors = [];

        foreach ($errors as $rowIndex => $rowErrors) {
            $gridErrors[$rowIndex] = array_map(
                fn($error) => $error->toArray(),
                $rowErrors
            );
        }

        return $gridErrors;
    }

    /**
     * Возвращает маппинг кодов полей CRM на ID колонок грида
     */
    private function getFieldToColumnMapping(string $entityType): array
    {
        $repository = EntityRegistry::createRepository($entityType);
        $fieldCodes = array_keys($repository->getFieldList());
        $mapping = [];

        foreach ($fieldCodes as $index => $code) {
            $mapping[$code] = 'COL_' . $index;
        }

        return $mapping;
    }

    /**
     * Получает параметры сортировки грида
     */
    private function getGridSort(): array
    {
        $gridOptions = new \Bitrix\Main\Grid\Options(self::GRID_ID);
        $sorting = $gridOptions->GetSorting([
            'sort' => ['COL_0' => 'ASC'],
            'vars' => ['by' => 'by', 'order' => 'order'],
        ]);

        $sort = $sorting['sort'] ?? [];
        $by = key($sort) ?: '';
        $order = current($sort) ?: 'ASC';

        return ['by' => $by, 'order' => $order];
    }

    /**
     * Получает размер страницы грида из настроек пользователя
     */
    private function getGridPageSize(): int
    {
        $gridOptions = new \Bitrix\Main\Grid\Options(self::GRID_ID);
        $navParams = $gridOptions->GetNavParams([
            'nPageSize' => self::GRID_DEFAULT_PAGE_SIZE,
        ]);

        $pageSize = (int) ($navParams['nPageSize'] ?? self::GRID_DEFAULT_PAGE_SIZE);

        if (!in_array($pageSize, self::GRID_ALLOWED_PAGE_SIZES, true)) {
            $pageSize = self::GRID_DEFAULT_PAGE_SIZE;
        }

        return $pageSize;
    }
}
