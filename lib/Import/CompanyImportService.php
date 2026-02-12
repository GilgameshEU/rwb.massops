<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\LoaderException;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Service\DuplicateChecker;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Сервис импорта компаний
 *
 * Расширяет базовый ImportService:
 * - проверка дубликатов по ИНН внутри файла
 * - проверка дубликатов по ИНН в CRM
 * - валидация обязательности ИНН
 */
class CompanyImportService extends ImportService
{
    private DuplicateChecker $duplicateChecker;
    private ?string $innFieldCode = null;

    public function __construct(
        CrmRepository $repository,
        RowNormalizer $normalizer = new RowNormalizer()
    ) {
        parent::__construct($repository, $normalizer);
        $this->duplicateChecker = new DuplicateChecker();
    }

    /**
     * Обрабатывает строки импорта с проверкой дублей
     *
     * Порядок проверок:
     * 1. Дубли внутри файла (по ИНН)
     * 2. Базовая валидация строки
     * 3. Дубли в CRM (по ИНН)
     * 4. Сохранение в CRM
     *
     * @throws LoaderException|ArgumentException|InvalidOperationException
     */
    protected function processRows(array $rows, ImportMode $mode, array $options = []): array
    {
        // Получаем коды полей из заголовков файла (если переданы колонки)
        $fieldCodes = $this->resolveFieldCodes($options['columns'] ?? []);
        $extractor = new ErrorFieldExtractor($this->repository->getFieldList());
        $innFieldCode = $this->getInnFieldCode();

        $success = 0;
        $errors = [];
        $items = [];

        $dryRun = ($mode === ImportMode::DryRun);

        // === ШАГ 1: Проверка дублей внутри файла ===
        $fileDuplicateErrors = [];
        if ($innFieldCode) {
            $fileDuplicateErrors = $this->checkFileInternalDuplicates($rows, $fieldCodes, $innFieldCode);
            foreach ($fileDuplicateErrors as $rowIndex => $error) {
                $errors[$rowIndex][] = $error;
            }
        }

        // === ШАГ 2: Нормализация и базовая валидация ===
        $normalizedRows = [];
        $validRowIndexes = [];

        foreach ($rows as $rowIndex => $row) {
            // Пропускаем строки с дублями в файле
            if (isset($fileDuplicateErrors[$rowIndex])) {
                continue;
            }

            $normalized = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );

            $fields = $normalized->fields;
            $uf = $normalized->uf;
            $fm = $normalized->fm;

            // Применяем опции импорта
            $this->applyImportOptions($uf, $options);

            $normalizedRows[$rowIndex] = [
                'fields' => $fields,
                'uf' => $uf,
                'fm' => $fm,
                'normalized' => $normalized,
            ];

            $hasErrors = false;

            // Ошибки нормализации
            if (!empty($normalized->errors)) {
                foreach ($normalized->errors as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError($error, $rowIndex);
                }
                $hasErrors = true;
            }

            // Бизнес-валидация
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

        // === ШАГ 3: Проверка дублей в CRM (только для валидных строк) ===
        $crmDuplicateErrors = [];
        if ($innFieldCode && !empty($validRowIndexes)) {
            $crmDuplicateErrors = $this->checkCrmDuplicates(
                $normalizedRows,
                $innFieldCode,
                $validRowIndexes
            );
            foreach ($crmDuplicateErrors as $rowIndex => $error) {
                $errors[$rowIndex][] = $error;
            }
        }

        // === ШАГ 4: Сохранение в CRM ===
        foreach ($validRowIndexes as $rowIndex) {
            // Пропускаем строки с дублями в CRM
            if (isset($crmDuplicateErrors[$rowIndex])) {
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
                        code: 'INVALID',
                        message: $error->getMessage(),
                        row: $rowIndex + 1,
                        field: $extractor->extractFieldCode($error)
                    );
                }
                continue;
            }

            $success++;
            $items[$rowIndex] = [
                'row' => $rowIndex + 1,
                'data' => $data['fields'],
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
     * Валидация строки компании
     *
     * Проверяет обязательность ИНН
     */
    protected function validateRow(array $fields, array $uf, array $fm): ValidationResult
    {
        $result = new ValidationResult();
        $innFieldCode = $this->getInnFieldCode();

        // ИНН обязателен
        if ($innFieldCode && empty($uf[$innFieldCode])) {
            $result->addError(
                new ImportError(
                    type: 'field',
                    code: 'REQUIRED',
                    message: 'ИНН обязателен для заполнения',
                    field: $innFieldCode
                )
            );
        }

        return $result;
    }

    /**
     * Проверяет дубли внутри файла
     */
    private function checkFileInternalDuplicates(array $rows, array $fieldCodes, string $innFieldCode): array
    {
        $preparedRows = [];

        foreach ($rows as $rowIndex => $row) {
            $normalized = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );
            $preparedRows[$rowIndex] = ['uf' => $normalized->uf];
        }

        return $this->duplicateChecker->checkFileInternalDuplicates($preparedRows, $innFieldCode);
    }

    /**
     * Проверяет дубли в CRM
     */
    private function checkCrmDuplicates(array $normalizedRows, string $innFieldCode, array $validRowIndexes): array
    {
        $preparedRows = [];

        foreach ($validRowIndexes as $rowIndex) {
            if (isset($normalizedRows[$rowIndex])) {
                $preparedRows[$rowIndex] = ['uf' => $normalizedRows[$rowIndex]['uf']];
            }
        }

        $excludeIndexes = array_diff(array_keys($normalizedRows), $validRowIndexes);

        return $this->duplicateChecker->checkCrmDuplicates(
            $preparedRows,
            $innFieldCode,
            $excludeIndexes
        );
    }

    /**
     * Возвращает код поля ИНН
     */
    private function getInnFieldCode(): ?string
    {
        if ($this->innFieldCode === null) {
            $this->innFieldCode = UserFieldHelper::getFieldCodeByXmlId('CRM_COMPANY', 'INN');
        }

        return $this->innFieldCode;
    }
}
