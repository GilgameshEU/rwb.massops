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
use Rwb\Massops\Import\Service\AImport;
use Rwb\Massops\Import\Service\CompanyImport;
use Rwb\Massops\Import\Service\ContactImport;
use Rwb\Massops\Import\Service\DealImport;
use Rwb\Massops\Repository\CRM\ARepository;
use Rwb\Massops\Repository\CRM\Company;
use Rwb\Massops\Repository\CRM\Contact;
use Rwb\Massops\Repository\CRM\Deal;

/**
 * Основной компонент массовых операций CRM
 */
class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    /**
     * Реестр поддерживаемых CRM-сущностей
     */
    private const ENTITY_MAP = [
        'company' => [
            'title' => 'Компании',
            'icon' => 'building',
            'repository' => Company::class,
            'import' => CompanyImport::class,
        ],
        'contact' => [
            'title' => 'Контакты',
            'icon' => 'person',
            'repository' => Contact::class,
            'import' => ContactImport::class,
        ],
        'deal' => [
            'title' => 'Сделки',
            'icon' => 'handshake',
            'repository' => Deal::class,
            'import' => DealImport::class,
        ],
    ];

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

        $entityTypes = [];
        foreach (self::ENTITY_MAP as $key => $config) {
            $entityTypes[$key] = [
                'title' => $config['title'],
                'icon' => $config['icon'],
            ];
        }

        $this->arResult['ENTITY_TYPES'] = $entityTypes;
        $this->arResult['CURRENT_ENTITY_TYPE'] = SessionStorage::getEntityType();
        $this->arResult['HAS_DATA'] = SessionStorage::hasData();
        $this->arResult['GRID_COLUMNS'] = SessionStorage::getColumns();
        $this->arResult['GRID_ROWS'] = SessionStorage::getRows();

        $this->includeComponentTemplate();
    }

    /**
     * Создает репозиторий для указанного типа сущности
     *
     * @param string $entityType
     *
     * @return ARepository
     */
    private function createRepository(string $entityType): ARepository
    {
        $config = $this->getEntityConfig($entityType);
        $repoClass = $config['repository'];

        return new $repoClass();
    }

    /**
     * Создает import-сервис для указанного типа сущности
     *
     * @param string $entityType
     *
     * @return AImport
     */
    private function createImportService(string $entityType): AImport
    {
        $config = $this->getEntityConfig($entityType);
        $importClass = $config['import'];
        $repository = $this->createRepository($entityType);

        return new $importClass($repository);
    }

    /**
     * Получает конфигурацию сущности по ключу
     *
     * @param string $entityType
     *
     * @return array
     */
    private function getEntityConfig(string $entityType): array
    {
        if (!isset(self::ENTITY_MAP[$entityType])) {
            throw new RuntimeException(
                'Неизвестный тип сущности: ' . $entityType
            );
        }

        return self::ENTITY_MAP[$entityType];
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

        if (!$entityType || !isset(self::ENTITY_MAP[$entityType])) {
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
        $importService = $this->createImportService($entityType);
        $repository = $this->createRepository($entityType);

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Файл не загружен');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $parseResult = $importService->parseFile(
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

        $entityConfig = $this->getEntityConfig($entityType);
        $validator = new FieldValidator();
        $validationErrors = $validator->validate(
            $rows,
            $repository,
            $entityConfig['title']
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
        $repository = $this->createRepository($entityType);
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
     * @throws AccessDeniedException|InvalidOperationException|LoaderException
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
        $importService = $this->createImportService($entityType);

        try {
            $result = $importService->import(
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
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     *
     * @return array
     * @throws AccessDeniedException|InvalidOperationException|LoaderException
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
        $importService = $this->createImportService($entityType);

        try {
            $result = $importService->dryRun(
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
            'fieldToColumn' => $this->getFieldToColumnMapping($entityType),
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
        $repository = $this->createRepository($entityType);

        $filename = $entityType . '_import_template.xlsx';

        XlsxTemplateExporter::export(
            $repository->getFieldList(),
            $repository->getRequiredFieldCodes(),
            $filename
        );
    }
}
