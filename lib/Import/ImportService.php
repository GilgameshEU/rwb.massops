<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\LoaderException;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Rwb\Massops\Import\Parser\CsvParser;
use Rwb\Massops\Import\Parser\XlsxParser;
use Rwb\Massops\Repository\CrmRepository;

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
    public function __construct(
        protected readonly CrmRepository $repository,
        protected readonly RowNormalizer $normalizer = new RowNormalizer()
    ) {
    }

    /**
     * Выполняет импорт данных
     *
     * @param array $rows Данные для импорта
     *
     * @return array{success: int, errors: array, added: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    public function import(array $rows): array
    {
        $result = $this->processRows($rows, ImportMode::Import);

        return [
            'success' => $result['success'],
            'errors' => $result['errors'],
            'added' => $result['items'],
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     *
     * @param array $rows Данные для импорта
     *
     * @return array{success: int, errors: array, wouldBeAdded: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    public function dryRun(array $rows): array
    {
        $result = $this->processRows($rows, ImportMode::DryRun);

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
     *
     * @return array{success: int, errors: array, items: array}
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    protected function processRows(array $rows, ImportMode $mode): array
    {
        $fieldCodes = array_keys(
            $this->repository->getFieldList()
        );

        $extractor = new ErrorFieldExtractor(
            $this->repository->getFieldList()
        );

        $success = 0;
        $errors = [];
        $items = [];

        $dryRun = ($mode === ImportMode::DryRun);

        foreach ($rows as $rowIndex => $row) {
            [$fields, $uf, $fm] = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );

            $hasErrors = false;

            // 1. Бизнес-валидация
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

            // 2. CRM-валидация / сохранение
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
}
