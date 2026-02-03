<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\UserTable;
use Rwb\Massops\EntityRegistry;
use Rwb\Massops\Import\FieldValidator;
use Rwb\Massops\Import\FileParser;
use Rwb\Massops\Queue\ImportJobStatus;
use Rwb\Massops\Queue\ImportJobTable;
use Rwb\Massops\Support\GridDataConverter;
use Rwb\Massops\Support\SessionStorage;
use Rwb\Massops\Support\XlsxTemplateExporter;

/**
 * Основной компонент массовых операций CRM
 */
class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    /**
     * Подготавливает параметры компонента
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
            'runImport' => ['prefilters' => []],
            'runDryRun' => ['prefilters' => []],
            'clear' => ['prefilters' => []],
            'startImport' => ['prefilters' => []],
            'getProgress' => ['prefilters' => []],
            'getStats' => ['prefilters' => []],
            // Обратная совместимость
            'importCompanies' => ['prefilters' => []],
            'dryRunImport' => ['prefilters' => []],
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
        $this->arResult['GRID_ROWS'] = SessionStorage::getRows();

        $this->includeComponentTemplate();
    }

    /**
     * Определяет тип сущности из запроса или сессии
     *
     * @return string
     */
    private function resolveEntityType(): string
    {
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $entityType = $request->getPost('entityType')
            ?? $request->get('entityType')
            ?? SessionStorage::getEntityType();

        if (!$entityType || !EntityRegistry::has($entityType)) {
            throw new RuntimeException('Тип сущности не указан');
        }

        return $entityType;
    }

    /**
     * Загружает и парсит файл импорта
     *
     * @return array
     * @throws AccessDeniedException
     */
    public function uploadFileAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

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
     * Возвращает маппинг кодов полей CRM на ID колонок грида
     *
     * @param string $entityType
     *
     * @return array<string, string>
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
     * Выполняет импорт из сохранённых данных
     *
     * @return array
     * @throws AccessDeniedException
     */
    public function runImportAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }

        $entityType = $this->resolveEntityType();
        $importService = EntityRegistry::createImportService($entityType);

        try {
            $result = $importService->import(
                SessionStorage::getRows()
            );
        } catch (ArgumentException|LoaderException $e) {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [['type' => 'system', 'code' => 'IMPORT_FAILED', 'message' => $e->getMessage()]],
            ];
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
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     *
     * @return array
     * @throws AccessDeniedException
     */
    public function runDryRunAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }

        $entityType = $this->resolveEntityType();
        $importService = EntityRegistry::createImportService($entityType);

        try {
            $result = $importService->dryRun(
                SessionStorage::getRows()
            );
        } catch (ArgumentException|LoaderException $e) {
            return [
                'success' => false,
                'wouldBeAdded' => 0,
                'errors' => [['type' => 'system', 'code' => 'DRY_RUN_FAILED', 'message' => $e->getMessage()]],
            ];
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
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
        ];
    }

    /**
     * Ставит импорт в очередь на фоновую обработку
     *
     * @return array{jobId: int}
     * @throws AccessDeniedException
     */
    public function startImportAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        if (!SessionStorage::hasData()) {
            throw new RuntimeException('Нет данных для импорта');
        }

        $entityType = $this->resolveEntityType();
        $rows = SessionStorage::getRows();

        $result = ImportJobTable::add([
            'USER_ID' => (int) CurrentUser::get()->getId(),
            'ENTITY_TYPE' => $entityType,
            'STATUS' => ImportJobStatus::Pending->value,
            'TOTAL_ROWS' => count($rows),
            'IMPORT_DATA' => serialize($rows),
        ]);

        if (!$result->isSuccess()) {
            throw new RuntimeException('Не удалось создать задачу импорта');
        }

        return [
            'jobId' => $result->getId(),
        ];
    }

    /**
     * Возвращает прогресс задачи импорта
     *
     * @return array
     * @throws AccessDeniedException
     */
    public function getProgressAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $jobId = (int) $request->getPost('jobId');

        if (!$jobId) {
            throw new RuntimeException('Job ID не указан');
        }

        $job = ImportJobTable::getById($jobId)->fetch();

        if (!$job) {
            throw new RuntimeException('Задача не найдена');
        }

        if ((int) $job['USER_ID'] !== (int) CurrentUser::get()->getId()) {
            throw new AccessDeniedException();
        }

        $isComplete = in_array($job['STATUS'], [
            ImportJobStatus::Completed->value,
            ImportJobStatus::Error->value,
        ], true);

        $totalRows = (int) $job['TOTAL_ROWS'];

        $response = [
            'status' => $job['STATUS'],
            'totalRows' => $totalRows,
            'processedRows' => (int) $job['PROCESSED_ROWS'],
            'successCount' => (int) $job['SUCCESS_COUNT'],
            'errorCount' => (int) $job['ERROR_COUNT'],
            'isComplete' => $isComplete,
            'progress' => $totalRows > 0
                ? round(((int) $job['PROCESSED_ROWS'] / $totalRows) * 100, 1)
                : 0,
        ];

        // При завершении — отдаём ошибки для подсветки грида
        if ($isComplete && !empty($job['ERRORS_DATA'])) {
            $errors = unserialize($job['ERRORS_DATA']);
            $gridErrors = [];

            foreach ($errors as $rowIndex => $rowErrors) {
                $gridErrors[$rowIndex] = array_map(
                    fn($error) => $error->toArray(),
                    $rowErrors
                );
            }

            $response['errors'] = $gridErrors;
            $response['fieldToColumn'] = $this->getFieldToColumnMapping($job['ENTITY_TYPE']);
        }

        return $response;
    }

    /**
     * Возвращает статистику задач импорта с пагинацией
     *
     * @return array
     * @throws AccessDeniedException
     */
    public function getStatsAction(): array
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $page = max(1, (int) $request->getPost('page'));
        $pageSize = 20;
        $offset = ($page - 1) * $pageSize;

        // Общее количество записей
        $countResult = ImportJobTable::getList([
            'select' => ['CNT'],
            'runtime' => [
                new ExpressionField('CNT', 'COUNT(*)'),
            ],
        ])->fetch();
        $totalCount = (int) ($countResult['CNT'] ?? 0);
        $totalPages = max(1, (int) ceil($totalCount / $pageSize));

        // Выборка задач без тяжёлых блобов IMPORT_DATA и ERRORS_DATA
        $dbResult = ImportJobTable::getList([
            'select' => [
                'ID', 'USER_ID', 'ENTITY_TYPE', 'STATUS',
                'TOTAL_ROWS', 'PROCESSED_ROWS', 'SUCCESS_COUNT', 'ERROR_COUNT',
                'CREATED_IDS',
                'CREATED_AT', 'STARTED_AT', 'FINISHED_AT',
            ],
            'order' => ['ID' => 'DESC'],
            'limit' => $pageSize,
            'offset' => $offset,
        ]);

        $jobs = [];
        $userIds = [];

        while ($row = $dbResult->fetch()) {
            $jobs[] = $row;
            $userIds[$row['USER_ID']] = true;
        }

        // Резолв имён пользователей одним запросом
        $userNames = [];
        if (!empty($userIds)) {
            $userResult = UserTable::getList([
                'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
                'filter' => ['=ID' => array_keys($userIds)],
            ]);
            while ($user = $userResult->fetch()) {
                $fullName = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? ''));
                if ($fullName === '') {
                    $fullName = $user['LOGIN'];
                }
                $userNames[(int) $user['ID']] = $fullName;
            }
        }

        // Маппинг ключей сущностей в названия
        $entityTitles = [];
        foreach (EntityRegistry::getAllForUi() as $key => $config) {
            $entityTitles[$key] = $config['title'];
        }

        // Форматирование результатов
        $items = [];
        foreach ($jobs as $job) {
            $userId = (int) $job['USER_ID'];
            $items[] = [
                'id' => (int) $job['ID'],
                'userId' => $userId,
                'userName' => $userNames[$userId] ?? ('User #' . $userId),
                'entityType' => $job['ENTITY_TYPE'],
                'entityTitle' => $entityTitles[$job['ENTITY_TYPE']] ?? $job['ENTITY_TYPE'],
                'status' => $job['STATUS'],
                'totalRows' => (int) $job['TOTAL_ROWS'],
                'processedRows' => (int) $job['PROCESSED_ROWS'],
                'successCount' => (int) $job['SUCCESS_COUNT'],
                'errorCount' => (int) $job['ERROR_COUNT'],
                'createdIds' => !empty($job['CREATED_IDS'])
                    ? unserialize($job['CREATED_IDS'])
                    : [],
                'createdAt' => $job['CREATED_AT'] ? $job['CREATED_AT']->toString() : null,
                'startedAt' => $job['STARTED_AT'] ? $job['STARTED_AT']->toString() : null,
                'finishedAt' => $job['FINISHED_AT'] ? $job['FINISHED_AT']->toString() : null,
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * Обратная совместимость: импорт компаний
     *
     * @return array
     */
    public function importCompaniesAction(): array
    {
        SessionStorage::saveEntityType('company');

        return $this->runImportAction();
    }

    /**
     * Обратная совместимость: dry run
     *
     * @return array
     */
    public function dryRunImportAction(): array
    {
        SessionStorage::saveEntityType('company');

        return $this->runDryRunAction();
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
     * Скачивает XLSX-шаблон для импорта
     *
     * @throws AccessDeniedException
     * @throws LoaderException
     */
    public function downloadXlsxTemplateAction(): void
    {
        if (!CurrentUser::get()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $entityType = $this->resolveEntityType();
        $repository = EntityRegistry::createRepository($entityType);

        $filename = $entityType . '_import_template.xlsx';

        XlsxTemplateExporter::export(
            $repository->getFieldList(),
            $repository->getRequiredFieldCodes(),
            $filename
        );
    }
}
