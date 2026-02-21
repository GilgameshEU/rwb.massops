<?php

namespace Rwb\Massops\Import;

use Rwb\Massops\Import\ImportErrorCode;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Service\DuplicateChecker;
use Rwb\Massops\Service\PhoneEmailDuplicateStrategy;

/**
 * Сервис импорта контактов
 *
 * Расширяет базовый ImportService через hook-методы:
 * - beforeProcessRows() — проверка дублей по телефону/email внутри файла
 * - beforeBatchSave()   — проверка дублей по телефону/email в CRM
 * - validateRow()       — контакт должен иметь имя или телефон/email
 */
class ContactImportService extends ImportService
{
    private DuplicateChecker $duplicateChecker;

    public function __construct(
        CrmRepository $repository,
        RowNormalizer $normalizer = new RowNormalizer()
    ) {
        parent::__construct($repository, $normalizer);
        $this->duplicateChecker = new DuplicateChecker(new PhoneEmailDuplicateStrategy());
    }

    /**
     * Hook: проверка дублей внутри загруженного файла (по телефону/email)
     *
     * Выполняем предварительную нормализацию, чтобы получить fm-данные (PHONE/EMAIL).
     */
    protected function beforeProcessRows(array $rows, array $fieldCodes, array $options): array
    {
        $preparedRows = [];
        foreach ($rows as $rowIndex => $row) {
            $normalized = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );
            $preparedRows[$rowIndex] = ['fm' => $normalized->fm];
        }

        // Передаём пустой innFieldCode — стратегия PhoneEmail его игнорирует
        return $this->duplicateChecker->checkFileInternalDuplicates($preparedRows, '');
    }

    /**
     * Hook: проверка дублей в CRM (по телефону/email)
     */
    protected function beforeBatchSave(array $normalizedRows, array $validRowIndexes, array $options): array
    {
        if (empty($validRowIndexes)) {
            return [];
        }

        // Фильтруем только строки, прошедшие базовую валидацию
        $validRows = array_intersect_key($normalizedRows, array_flip($validRowIndexes));

        // Передаём пустой innFieldCode — стратегия PhoneEmail его игнорирует
        return $this->duplicateChecker->checkCrmDuplicates($validRows, '');
    }

    /**
     * Валидация строки контакта — контакт должен иметь имя или телефон/email
     */
    protected function validateRow(array $fields, array $uf, array $fm): ValidationResult
    {
        $result = new ValidationResult();

        if (
            empty($fields['NAME'])
            && empty($fm['PHONE'])
            && empty($fm['EMAIL'])
        ) {
            $result->addError(
                new ImportError(
                    type: 'field',
                    code: ImportErrorCode::Required->value,
                    message: 'Контакт должен иметь имя или телефон / email',
                    field: 'NAME'
                )
            );
        }

        return $result;
    }
}
