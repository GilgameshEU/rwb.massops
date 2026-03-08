<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\LoaderException;
use Bitrix\Crm\Field;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Service\CrmEntityResolver;
use Rwb\Massops\Service\IblockResolver;
use Rwb\Massops\Service\UserResolver;
use Rwb\Massops\Support\UserFieldHelper;
use Rwb\Massops\Import\ImportErrorCode;

/**
 * Сервис импорта данных CRM
 *
 * Реализует общий алгоритм импорта:
 * - нормализация строк
 * - валидация
 * - сохранение через репозиторий
 *
 * Расширяется через hook-методы:
 * - beforeProcessRows()  — предобработка (дубли в файле и т.д.)
 * - beforeSave()         — проверки перед сохранением строки
 * - afterSave()          — постобработка после успешного сохранения
 */
class ImportService
{
    protected readonly UserResolver $userResolver;
    protected readonly IblockResolver $iblockResolver;
    protected readonly CrmEntityResolver $crmEntityResolver;

    public function __construct(
        protected readonly CrmRepository $repository,
        protected readonly RowNormalizer $normalizer = new RowNormalizer(),
        ?UserResolver $userResolver = null,
        ?IblockResolver $iblockResolver = null,
        ?CrmEntityResolver $crmEntityResolver = null,
    ) {
        $this->userResolver = $userResolver ?? new UserResolver();
        $this->iblockResolver = $iblockResolver ?? new IblockResolver();
        $this->crmEntityResolver = $crmEntityResolver ?? new CrmEntityResolver();
    }

    /**
     * Выполняет импорт данных
     *
     * @param array $rows    Данные для импорта
     * @param array $options Дополнительные опции импорта
     *
     * @return array{success: int, errors: array, added: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    public function import(array $rows, array $options = []): array
    {
        $result = $this->processRows($rows, ImportMode::Import, $options);

        return [
            'success' => $result['success'],
            'errors' => $result['errors'],
            'added' => $result['items'],
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     *
     * @param array $rows    Данные для импорта
     * @param array $options Дополнительные опции импорта
     *
     * @return array{success: int, errors: array, wouldBeAdded: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    public function dryRun(array $rows, array $options = []): array
    {
        $result = $this->processRows($rows, ImportMode::DryRun, $options);

        return [
            'success' => $result['success'],
            'errors' => $result['errors'],
            'wouldBeAdded' => $result['items'],
        ];
    }

    /**
     * Обрабатывает строки импорта
     *
     * @param array $rows       Данные строк
     * @param ImportMode $mode  Режим импорта
     * @param array $options    Дополнительные опции
     *
     * @return array{success: int, errors: array, items: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    final protected function processRows(array $rows, ImportMode $mode, array $options = []): array
    {
        $fieldCodes = $this->resolveFieldCodes($options['columns'] ?? []);
        $fieldTypes = $this->repository->getFieldTypeMap();
        $multipleFields = $this->repository->getMultipleFieldCodes();
        $enumMappings = $this->repository->getEnumMappings();
        $ufSettings = $this->repository->getUfFieldsSettings();

        $errors = [];

        // Hook: предобработка всего набора строк (например, поиск дублей в файле)
        $preErrors = $this->beforeProcessRows($rows, $fieldCodes, $options);
        foreach ($preErrors as $rowIndex => $error) {
            $errors[$rowIndex][] = $error;
        }

        [$normalizedRows, $validRowIndexes, $rowErrors] = $this->normalizeRows(
            $rows,
            $preErrors,
            $fieldCodes,
            $fieldTypes,
            $multipleFields,
            $enumMappings,
            $ufSettings,
            $options
        );

        foreach ($rowErrors as $rowIndex => $rowErrorList) {
            foreach ($rowErrorList as $error) {
                $errors[$rowIndex][] = $error;
            }
        }

        // Hook: проверки перед сохранением (например, дубли в CRM)
        $savePreErrors = $this->beforeBatchSave($normalizedRows, $validRowIndexes, $options);
        foreach ($savePreErrors as $rowIndex => $error) {
            $errors[$rowIndex][] = $error;
        }

        [$success, $items, $saveErrors] = $this->saveValidRows(
            $normalizedRows,
            $validRowIndexes,
            $savePreErrors,
            $mode
        );

        foreach ($saveErrors as $rowIndex => $rowErrorList) {
            foreach ($rowErrorList as $error) {
                $errors[$rowIndex][] = $error;
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'items' => $items,
        ];
    }

    /**
     * Нормализует и валидирует каждую строку, пропуская уже отмеченные ошибками
     *
     * @return array{0: array, 1: array, 2: array} [normalizedRows, validRowIndexes, errors]
     */
    private function normalizeRows(
        array $rows,
        array $preErrors,
        array $fieldCodes,
        array $fieldTypes,
        array $multipleFields,
        array $enumMappings,
        array $ufSettings,
        array $options
    ): array {
        $normalizedRows = [];
        $validRowIndexes = [];
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            if (isset($preErrors[$rowIndex])) {
                continue;
            }

            // $fieldCodes содержит '' на позиции ROW_NUM (см. resolveFieldCodes).
            // RowNormalizer пропускает поля с пустым кодом, поэтому ROW_NUM-значение
            // на позиции 0 безопасно: оно совпадает с placeholder'ом в fieldCodes.
            $rowData = array_values($row['data']);

            $normalized = $this->normalizer->normalize(
                $rowData,
                $fieldCodes,
                $fieldTypes,
                $multipleFields,
                $enumMappings
            );

            $fields = $normalized->fields;
            $uf = $normalized->uf;
            $fm = $normalized->fm;

            $userErrors = $this->resolveUserFields($fields, $uf, $fieldTypes);
            $iblockErrors = $this->resolveIblockFields($uf, $fieldTypes, $ufSettings);
            $crmRefErrors = $this->resolveCrmEntityFields($fields, $fieldTypes);

            $this->applyImportOptions($uf, $options);

            $normalizedRows[$rowIndex] = compact('fields', 'uf', 'fm', 'normalized');

            $hasErrors = false;

            $resolutionErrors = array_merge($userErrors, $iblockErrors, $crmRefErrors);
            if (!empty($resolutionErrors)) {
                foreach ($resolutionErrors as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError($error, $rowIndex);
                }
                $hasErrors = true;
            }

            if (!empty($normalized->errors)) {
                foreach ($normalized->errors as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError($error, $rowIndex);
                }
                $hasErrors = true;
            }

            $validation = $this->validateRow($fields, $uf, $fm);
            if (!$validation->isValid()) {
                foreach ($validation->getErrors() as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError($error, $rowIndex);
                }
                $hasErrors = true;
            }

            if (!$hasErrors) {
                $validRowIndexes[] = $rowIndex;
            }
        }

        return [$normalizedRows, $validRowIndexes, $errors];
    }

    /**
     * Сохраняет валидные строки через репозиторий (или симулирует в dry-run режиме)
     *
     * @return array{0: int, 1: array, 2: array} [success, items, errors]
     */
    private function saveValidRows(
        array $normalizedRows,
        array $validRowIndexes,
        array $savePreErrors,
        ImportMode $mode
    ): array {
        $dryRun = ($mode === ImportMode::DryRun);
        $extractor = new ErrorFieldExtractor($this->repository->getFieldList());

        $success = 0;
        $items = [];
        $errors = [];

        foreach ($validRowIndexes as $rowIndex) {
            if (isset($savePreErrors[$rowIndex])) {
                continue;
            }

            $data = $normalizedRows[$rowIndex];

            $result = $this->repository->add(
                $data['fields'],
                $data['uf'],
                $data['fm'],
                $dryRun
            );

            if (!$result->isSuccess()) {
                foreach ($result->getErrors() as $error) {
                    $errors[$rowIndex][] = new ImportError(
                        type: 'validation',
                        code: ImportErrorCode::Invalid->value,
                        message: $error->getMessage(),
                        row: $rowIndex + 1,
                        field: $extractor->extractFieldCode($error)
                    );
                }
                continue;
            }

            $entityId = !$dryRun && method_exists($result, 'getId')
                ? $result->getId()
                : null;

            $extra = [];
            if ($entityId && !$dryRun) {
                $extra = $this->afterSave($rowIndex, $entityId, $data['fields'], $data['uf']);
            }

            $success++;
            $items[$rowIndex] = [
                'row' => $rowIndex + 1,
                'data' => $data['fields'],
                'entityId' => $entityId,
                'cid' => $extra['cid'] ?? null,
            ];
        }

        return [$success, $items, $errors];
    }

    /**
     * Hook: предобработка всего набора строк
     *
     * Вызывается до нормализации. Используется для проверок на уровне
     * всего файла, например поиска внутренних дублей.
     *
     * @param array $rows       Исходные строки
     * @param array $fieldCodes Коды полей (маппинг колонок)
     * @param array $options    Опции импорта
     *
     * @return array<int, ImportError> Ошибки, индексированные по rowIndex
     */
    protected function beforeProcessRows(array $rows, array $fieldCodes, array $options): array
    {
        return [];
    }

    /**
     * Hook: проверки перед сохранением пакета
     *
     * Вызывается после нормализации и базовой валидации.
     * Используется для проверок, требующих нормализованных данных, —
     * например поиска дублей в CRM.
     *
     * @param array $normalizedRows  Нормализованные строки [rowIndex => ['fields', 'uf', 'fm', 'normalized']]
     * @param array $validRowIndexes Индексы строк, прошедших базовую валидацию
     * @param array $options         Опции импорта
     *
     * @return array<int, ImportError> Ошибки, индексированные по rowIndex
     */
    protected function beforeBatchSave(array $normalizedRows, array $validRowIndexes, array $options): array
    {
        return [];
    }

    /**
     * Hook: постобработка после успешного сохранения строки
     *
     * Вызывается после успешного сохранения каждой записи в режиме import.
     * Используется, например, для привязки источника сквозной аналитики
     * или генерации производных полей (CID и т.д.).
     *
     * Может вернуть массив дополнительных данных, которые будут добавлены
     * к записи в результате импорта. Поддерживаемые ключи:
     * - 'cid' (string|null) — сгенерированный CID компании
     *
     * @param int   $rowIndex Индекс строки
     * @param int   $entityId ID созданной сущности
     * @param array $fields   Стандартные поля сохранённой записи
     * @param array $uf       Пользовательские поля сохранённой записи
     *
     * @return array Дополнительные данные для включения в результат
     */
    protected function afterSave(int $rowIndex, int $entityId, array $fields, array $uf = []): array
    {
        return [];
    }

    /**
     * Привязывает номер строки к ошибке импорта
     */
    protected function attachRowToError(ImportError $error, int $rowIndex): ImportError
    {
        if ($error->row !== null) {
            return $error;
        }

        return new ImportError(
            type: $error->type,
            code: $error->code,
            message: $error->message,
            row: $rowIndex + 1,
            field: $error->field,
            context: $error->context
        );
    }

    /**
     * Выполняет бизнес-валидацию строки импорта
     *
     * По умолчанию — пустая (CRM сама проверяет обязательные поля).
     * Переопределяется в подклассах при наличии кастомной логики.
     */
    protected function validateRow(array $fields, array $uf, array $fm): ValidationResult
    {
        return new ValidationResult();
    }

    /**
     * Применяет опции импорта к пользовательским полям
     *
     * @param array $uf      Ссылка на массив пользовательских полей
     * @param array $options Опции импорта
     */
    protected function applyImportOptions(array &$uf, array $options): void
    {
        if (!empty($options['createCabinets'])) {
            $fieldCode = UserFieldHelper::getCompanyCommentsField();
            if ($fieldCode) {
                $uf[$fieldCode] = ['need_suppliers'];
            }
        }
    }

    /**
     * Резолюция полей типа "Пользователь" и "Сотрудник"
     *
     * Для стандартных полей с типом Field::TYPE_USER (например ASSIGNED_BY_ID)
     * и UF-полей с USER_TYPE_ID='employee':
     * - числовой ID -> проверка существования
     * - "Имя Фамилия" -> поиск пользователя
     *
     * @param array $fields     Ссылка на массив стандартных полей
     * @param array $uf         Ссылка на массив UF-полей
     * @param array $fieldTypes Карта типов полей (код => тип)
     *
     * @return ImportError[]
     */
    protected function resolveUserFields(array &$fields, array &$uf, array $fieldTypes): array
    {
        $errors = [];

        foreach ($fields as $code => $value) {
            $fieldType = $fieldTypes[$code] ?? null;

            if ($fieldType !== Field::TYPE_USER) {
                continue;
            }

            $userId = $this->userResolver->resolve((string) $value);

            if ($userId === null) {
                $errors[] = new ImportError(
                    type: 'field',
                    code: ImportErrorCode::UserNotFound->value,
                    message: 'Пользователь не найден: ' . $value,
                    field: $code
                );
                unset($fields[$code]);
                continue;
            }

            $fields[$code] = $userId;
        }

        foreach ($uf as $code => $value) {
            $fieldType = $fieldTypes[$code] ?? null;

            if ($fieldType !== 'employee') {
                continue;
            }

            if (is_array($value)) {
                $resolvedValues = [];
                foreach ($value as $v) {
                    $userId = $this->userResolver->resolve((string) $v);
                    if ($userId === null) {
                        $errors[] = new ImportError(
                            type: 'field',
                            code: ImportErrorCode::UserNotFound->value,
                            message: 'Сотрудник не найден: ' . $v,
                            field: $code
                        );
                    } else {
                        $resolvedValues[] = $userId;
                    }
                }
                $uf[$code] = $resolvedValues;
            } else {
                $userId = $this->userResolver->resolve((string) $value);
                if ($userId === null) {
                    $errors[] = new ImportError(
                        type: 'field',
                        code: ImportErrorCode::UserNotFound->value,
                        message: 'Сотрудник не найден: ' . $value,
                        field: $code
                    );
                    unset($uf[$code]);
                } else {
                    $uf[$code] = $userId;
                }
            }
        }

        return $errors;
    }

    /**
     * Резолюция полей типа "Элемент инфоблока" и "Раздел инфоблока"
     *
     * @param array $uf         Ссылка на массив UF-полей
     * @param array $fieldTypes Карта типов полей
     * @param array $ufSettings Настройки UF-полей (для IBLOCK_ID)
     *
     * @return ImportError[]
     */
    protected function resolveIblockFields(array &$uf, array $fieldTypes, array $ufSettings): array
    {
        $errors = [];

        foreach ($uf as $code => $value) {
            $fieldType = $fieldTypes[$code] ?? null;

            if ($fieldType !== 'iblock_element' && $fieldType !== 'iblock_section') {
                continue;
            }

            $iblockId = (int) ($ufSettings[$code]['IBLOCK_ID'] ?? 0);
            if ($iblockId <= 0) {
                continue;
            }

            $isElement = ($fieldType === 'iblock_element');
            $entityLabel = $isElement ? 'Элемент инфоблока' : 'Раздел инфоблока';
            $iblockType = $this->iblockResolver->getIblockTypeId($iblockId);
            $errorContext = ['iblockId' => $iblockId, 'iblockType' => $iblockType];

            if (is_array($value)) {
                $resolved = [];
                foreach ($value as $v) {
                    $id = $isElement
                        ? $this->iblockResolver->resolveElement((string) $v, $iblockId)
                        : $this->iblockResolver->resolveSection((string) $v, $iblockId);

                    if ($id === null) {
                        $errors[] = new ImportError(
                            type: 'field',
                            code: ImportErrorCode::IblockNotFound->value,
                            message: "{$entityLabel} не найден: {$v}",
                            field: $code,
                            context: $errorContext
                        );
                    } else {
                        $resolved[] = $id;
                    }
                }
                $uf[$code] = $resolved;
            } else {
                $id = $isElement
                    ? $this->iblockResolver->resolveElement((string) $value, $iblockId)
                    : $this->iblockResolver->resolveSection((string) $value, $iblockId);

                if ($id === null) {
                    $errors[] = new ImportError(
                        type: 'field',
                        code: ImportErrorCode::IblockNotFound->value,
                        message: "{$entityLabel} не найден: {$value}",
                        field: $code,
                        context: $errorContext
                    );
                    unset($uf[$code]);
                } else {
                    $uf[$code] = $id;
                }
            }
        }

        return $errors;
    }

    /**
     * Валидация ссылок на CRM-сущности
     *
     * Для полей типа crm_company, crm_contact, crm_deal, crm_lead:
     * - проверяет что значение числовое
     * - проверяет что сущность с таким ID существует
     *
     * @param array $fields     Ссылка на массив стандартных полей
     * @param array $fieldTypes Карта типов полей
     *
     * @return ImportError[]
     */
    protected function resolveCrmEntityFields(array &$fields, array $fieldTypes): array
    {
        $errors = [];

        foreach ($fields as $code => $value) {
            $fieldType = $fieldTypes[$code] ?? null;

            if (!$this->crmEntityResolver->supportsType($fieldType ?? '')) {
                continue;
            }

            if (!ctype_digit((string) $value)) {
                $label = $this->crmEntityResolver->getTypeLabel($fieldType);
                $errors[] = new ImportError(
                    type: 'field',
                    code: ImportErrorCode::InvalidCrmRef->value,
                    message: "{$label}: значение «{$value}» должно быть числовым ID",
                    field: $code
                );
                unset($fields[$code]);
                continue;
            }

            if (!$this->crmEntityResolver->exists((int) $value, $fieldType)) {
                $label = $this->crmEntityResolver->getTypeLabel($fieldType);
                $errors[] = new ImportError(
                    type: 'field',
                    code: ImportErrorCode::CrmEntityNotFound->value,
                    message: "{$label} с ID {$value} не найдена в CRM",
                    field: $code
                );
                unset($fields[$code]);
            }
        }

        return $errors;
    }

    /**
     * Определяет коды полей CRM по заголовкам файла
     *
     * Если переданы колонки из грида, маппим их названия на коды CRM.
     * Иначе возвращаем все коды полей в порядке из репозитория (legacy-поведение).
     *
     * @param array $columns Колонки грида [{id, name}, ...]
     *
     * @return array Массив кодов полей CRM
     * @throws LoaderException
     */
    protected function resolveFieldCodes(array $columns): array
    {
        if (empty($columns)) {
            return array_keys($this->repository->getFieldList());
        }

        $fieldList = $this->repository->getFieldList();
        $titleToCode = array_flip($fieldList);

        $fieldCodes = [];
        foreach ($columns as $column) {
            $columnId = $column['id'] ?? '';

            if ($columnId === 'ROW_NUM') {
                $fieldCodes[] = '';
                continue;
            }

            $title = $column['name'] ?? '';
            $code = $titleToCode[$title] ?? null;

            $fieldCodes[] = $code ?? '';
        }

        return $fieldCodes;
    }
}
