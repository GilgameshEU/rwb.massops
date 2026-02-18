<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
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
    /**
     * UTM-метка источника для сквозной аналитики
     */
    private const TRACKING_UTM_SOURCE = 'rwb.massops';

    private DuplicateChecker $duplicateChecker;
    private ?string $innFieldCode = null;
    private ?int $trackingSourceId = null;
    private bool $trackingSourceResolved = false;

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
        $fieldCodes = $this->resolveFieldCodes($options['columns'] ?? []);
        $fieldTypes = $this->repository->getFieldTypeMap();
        $multipleFields = $this->repository->getMultipleFieldCodes();
        $enumMappings = $this->repository->getEnumMappings();
        $ufSettings = $this->repository->getUfFieldsSettings();
        $extractor = new ErrorFieldExtractor($this->repository->getFieldList());
        $innFieldCode = $this->getInnFieldCode();

        $success = 0;
        $errors = [];
        $items = [];

        $dryRun = ($mode === ImportMode::DryRun);

        $fileDuplicateErrors = [];
        if ($innFieldCode) {
            $fileDuplicateErrors = $this->checkFileInternalDuplicates($rows, $fieldCodes, $innFieldCode);
            foreach ($fileDuplicateErrors as $rowIndex => $error) {
                $errors[$rowIndex][] = $error;
            }
        }

        $normalizedRows = [];
        $validRowIndexes = [];

        foreach ($rows as $rowIndex => $row) {
            if (isset($fileDuplicateErrors[$rowIndex])) {
                continue;
            }

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

            $userErrors = $this->resolveUserFields($fields, $uf, $fieldTypes);
            $iblockErrors = $this->resolveIblockFields($uf, $fieldTypes, $ufSettings);
            $crmRefErrors = $this->resolveCrmEntityFields($fields, $fieldTypes);

            $this->applyImportOptions($uf, $options);

            $normalizedRows[$rowIndex] = [
                'fields' => $fields,
                'uf' => $uf,
                'fm' => $fm,
                'normalized' => $normalized,
            ];

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

        foreach ($validRowIndexes as $rowIndex) {
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

            $entityId = !$dryRun && method_exists($result, 'getId')
                ? $result->getId()
                : null;

            if ($entityId && !$dryRun) {
                $this->assignTrackingSource($entityId);
            }

            $success++;
            $items[$rowIndex] = [
                'row' => $rowIndex + 1,
                'data' => $data['fields'],
                'entityId' => $entityId,
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

    /**
     * Возвращает ID источника сквозной аналитики по UTM-метке
     */
    private function getTrackingSourceId(): ?int
    {
        if (!$this->trackingSourceResolved) {
            $this->trackingSourceResolved = true;

            Loader::requireModule('crm');

            if (class_exists('\Bitrix\Crm\Tracking\Internals\SourceTable')) {
                $this->trackingSourceId = \Bitrix\Crm\Tracking\Internals\SourceTable::getSourceByUtmSource(
                    self::TRACKING_UTM_SOURCE
                );
            }
        }

        return $this->trackingSourceId;
    }

    /**
     * Привязывает источник сквозной аналитики к созданной компании
     *
     * @param int $entityId ID созданной компании
     */
    private function assignTrackingSource(int $entityId): void
    {
        $sourceId = $this->getTrackingSourceId();
        if (!$sourceId) {
            return;
        }

        \Bitrix\Crm\Tracking\UI\Details::saveEntityData(
            \CCrmOwnerType::Company,
            $entityId,
            ['TRACKING_SOURCE_ID' => $sourceId],
            true
        );
    }
}
