<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\Loader;
use Rwb\Massops\Import\ImportErrorCode;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Service\DuplicateChecker;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Сервис импорта компаний
 *
 * Расширяет базовый ImportService через hook-методы:
 * - beforeProcessRows() — проверка дубликатов по ИНН внутри файла
 * - beforeBatchSave()   — проверка дубликатов по ИНН в CRM
 * - afterSave()         — привязка источника сквозной аналитики
 * - validateRow()       — валидация обязательности ИНН
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
     * Hook: проверка дублей внутри загруженного файла (по ИНН)
     */
    protected function beforeProcessRows(array $rows, array $fieldCodes, array $options): array
    {
        $innFieldCode = $this->getInnFieldCode();
        if (!$innFieldCode) {
            return [];
        }

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
     * Hook: проверка дублей в CRM (по ИНН)
     */
    protected function beforeBatchSave(array $normalizedRows, array $validRowIndexes, array $options): array
    {
        $innFieldCode = $this->getInnFieldCode();
        if (!$innFieldCode || empty($validRowIndexes)) {
            return [];
        }

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
     * Hook: привязка источника сквозной аналитики к созданной компании
     */
    protected function afterSave(int $rowIndex, int $entityId, array $fields): void
    {
        $this->assignTrackingSource($entityId);
    }

    /**
     * Валидация строки компании — проверяет обязательность ИНН
     */
    protected function validateRow(array $fields, array $uf, array $fm): ValidationResult
    {
        $result = new ValidationResult();
        $innFieldCode = $this->getInnFieldCode();

        if ($innFieldCode && empty($uf[$innFieldCode])) {
            $result->addError(
                new ImportError(
                    type: 'field',
                    code: ImportErrorCode::Required->value,
                    message: 'ИНН обязателен для заполнения',
                    field: $innFieldCode
                )
            );
        }

        return $result;
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
