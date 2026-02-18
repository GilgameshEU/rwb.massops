<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\LoaderException;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Bitrix\Crm\Field;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Service\UserResolver;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Сервис импорта данных CRM
 *
 * Реализует общий алгоритм импорта:
 * - нормализация строк
 * - валидация
 * - сохранение через репозиторий
 */
class ImportService
{
    protected readonly UserResolver $userResolver;

    public function __construct(
        protected readonly CrmRepository $repository,
        protected readonly RowNormalizer $normalizer = new RowNormalizer()
    ) {
        $this->userResolver = new UserResolver();
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
    protected function processRows(array $rows, ImportMode $mode, array $options = []): array
    {
        $fieldCodes = $this->resolveFieldCodes($options['columns'] ?? []);
        $fieldTypes = $this->repository->getFieldTypeMap();
        $multipleFields = $this->repository->getMultipleFieldCodes();
        $enumMappings = $this->repository->getEnumMappings();

        $extractor = new ErrorFieldExtractor(
            $this->repository->getFieldList()
        );

        $success = 0;
        $errors = [];
        $items = [];

        $dryRun = ($mode === ImportMode::DryRun);

        foreach ($rows as $rowIndex => $row) {
            $normalized = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes,
                $fieldTypes,
                $multipleFields,
                $enumMappings
            );

            $fields = $normalized->fields;
            $uf = $normalized->uf;
            $fm = $normalized->fm;

            $userErrors = $this->resolveUserFields($fields, $fieldTypes);

            $this->applyImportOptions($uf, $options);

            $hasErrors = false;

            if (!empty($userErrors)) {
                foreach ($userErrors as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError(
                        $error,
                        $rowIndex
                    );
                }
                $hasErrors = true;
            }

            if (!empty($normalized->errors)) {
                foreach ($normalized->errors as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError(
                        $error,
                        $rowIndex
                    );
                }
                $hasErrors = true;
            }

            $validation = $this->validateRow($fields, $uf, $fm);

            if (!$validation->isValid()) {
                foreach ($validation->getErrors() as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError(
                        $error,
                        $rowIndex
                    );
                }
                $hasErrors = true;
            }

            $result = $this->repository->add(
                $fields,
                $uf,
                $fm,
                $dryRun
            );

            if (!$result->isSuccess()) {
                foreach ($result->getErrors() as $error) {
                    $errors[$rowIndex][] = new ImportError(
                        type: 'validation',
                        code: 'INVALID',
                        message: $error->getMessage(),
                        row: $rowIndex + 1,
                        field: $extractor->extractFieldCode($error)
                    );
                }
                $hasErrors = true;
            }

            if ($hasErrors) {
                continue;
            }

            $success++;
            $items[$rowIndex] = [
                'row' => $rowIndex + 1,
                'data' => $fields,
                'entityId' => !$dryRun && method_exists($result, 'getId')
                    ? $result->getId()
                    : null,
            ];
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'items' => $items,
        ];
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
     * Резолюция полей типа "Пользователь"
     *
     * Для полей с типом Field::TYPE_USER (например ASSIGNED_BY_ID):
     * - числовой ID → проверка существования
     * - "Имя Фамилия" → поиск пользователя
     *
     * @param array $fields     Ссылка на массив полей (значения заменяются на ID)
     * @param array $fieldTypes Карта типов полей (код → тип)
     *
     * @return ImportError[] Ошибки для ненайденных пользователей
     */
    protected function resolveUserFields(array &$fields, array $fieldTypes): array
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
                    code: 'USER_NOT_FOUND',
                    message: 'Пользователь не найден: ' . $value,
                    field: $code
                );
                unset($fields[$code]);
                continue;
            }

            $fields[$code] = $userId;
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
