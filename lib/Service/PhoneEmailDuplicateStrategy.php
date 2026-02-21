<?php

namespace Rwb\Massops\Service;

use Bitrix\Crm\ContactTable;
use Bitrix\Main\Loader;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ImportErrorCode;

/**
 * Стратегия поиска дублей контактов по телефону и email
 *
 * Ищет дубликаты:
 * 1. Внутри загружаемого файла — по полям PHONE и EMAIL из fm-данных
 * 2. В существующей базе CRM — через ORM-запрос к многозначным полям контакта
 */
class PhoneEmailDuplicateStrategy implements DuplicateStrategy
{
    /**
     * Проверяет дубли телефонов/email внутри файла
     *
     * Ожидает $rows — нормализованные строки с ключом 'fm'.
     * Если в строке есть хоть один телефон или email, они попадают в проверку.
     */
    public function checkFileInternalDuplicates(array $rows, array $extra = []): array
    {
        $errors = [];
        $phoneToRows = [];
        $emailToRows = [];

        foreach ($rows as $rowIndex => $row) {
            $phones = $this->extractValues($row, 'PHONE');
            $emails = $this->extractValues($row, 'EMAIL');

            foreach ($phones as $phone) {
                $phoneToRows[$phone][] = $rowIndex;
            }
            foreach ($emails as $email) {
                $emailToRows[$email][] = $rowIndex;
            }
        }

        $errors = array_merge(
            $errors,
            $this->buildFileErrors($phoneToRows, 'PHONE', 'телефону')
        );
        $errors = array_merge(
            $errors,
            $this->buildFileErrors($emailToRows, 'EMAIL', 'email')
        );

        return $errors;
    }

    /**
     * Проверяет дубли контактов по телефону/email в CRM
     */
    public function checkCrmDuplicates(array $normalizedRows, array $validRowIndexes, array $extra = []): array
    {
        Loader::requireModule('crm');

        $errors = [];

        // Собираем телефоны и email из валидных строк
        $phoneToRow = [];
        $emailToRow = [];

        foreach ($validRowIndexes as $rowIndex) {
            $row = $normalizedRows[$rowIndex] ?? null;
            if (!$row) {
                continue;
            }

            $phones = $this->extractNormalizedValues($row, 'PHONE');
            $emails = $this->extractNormalizedValues($row, 'EMAIL');

            foreach ($phones as $phone) {
                if (!isset($phoneToRow[$phone])) {
                    $phoneToRow[$phone] = $rowIndex;
                }
            }
            foreach ($emails as $email) {
                if (!isset($emailToRow[$email])) {
                    $emailToRow[$email] = $rowIndex;
                }
            }
        }

        if (!empty($phoneToRow)) {
            $found = $this->findContactsByMultiField('PHONE', array_keys($phoneToRow));
            foreach ($found as $value => $contactId) {
                if (isset($phoneToRow[$value])) {
                    $rowIndex = $phoneToRow[$value];
                    $errors[$rowIndex] = $this->makeCrmError($rowIndex, 'PHONE', $value, $contactId, 'телефону');
                }
            }
        }

        if (!empty($emailToRow)) {
            $found = $this->findContactsByMultiField('EMAIL', array_keys($emailToRow));
            foreach ($found as $value => $contactId) {
                if (isset($emailToRow[$value]) && !isset($errors[$emailToRow[$value]])) {
                    $rowIndex = $emailToRow[$value];
                    $errors[$rowIndex] = $this->makeCrmError($rowIndex, 'EMAIL', $value, $contactId, 'email');
                }
            }
        }

        return $errors;
    }

    /**
     * Строит ошибки дублей внутри файла для одного типа поля
     *
     * @param array<string, int[]> $valueToRows
     * @return array<int, ImportError>
     */
    private function buildFileErrors(array $valueToRows, string $fieldCode, string $fieldLabel): array
    {
        $errors = [];

        foreach ($valueToRows as $value => $rowIndexes) {
            if (count($rowIndexes) > 1) {
                foreach ($rowIndexes as $rowIndex) {
                    if (isset($errors[$rowIndex])) {
                        continue; // Уже есть ошибка для этой строки
                    }

                    $duplicateRows = array_diff($rowIndexes, [$rowIndex]);
                    $duplicateRowsHuman = array_map(fn($i) => $i + 1, $duplicateRows);

                    $errors[$rowIndex] = new ImportError(
                        type: 'duplicate',
                        code: ImportErrorCode::DuplicateInFile->value,
                        message: "Дубликат по {$fieldLabel} в файле (строки: " . implode(', ', $duplicateRowsHuman) . ')',
                        row: $rowIndex + 1,
                        field: $fieldCode,
                        context: [
                            'value' => $value,
                            'duplicateRows' => $duplicateRowsHuman,
                        ]
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Строит ошибку дубля в CRM
     */
    private function makeCrmError(int $rowIndex, string $fieldCode, string $value, int $contactId, string $fieldLabel): ImportError
    {
        return new ImportError(
            type: 'duplicate',
            code: ImportErrorCode::DuplicateInCrm->value,
            message: "Контакт с таким {$fieldLabel} уже существует в CRM (ID: {$contactId})",
            row: $rowIndex + 1,
            field: $fieldCode,
            context: [
                'value' => $value,
                'existingContactId' => $contactId,
            ]
        );
    }

    /**
     * Извлекает значения многозначного поля из raw-строки
     *
     * @return string[]
     */
    private function extractValues(array $row, string $fieldCode): array
    {
        // Raw строки хранят fm под ключом 'fm' или прямо как массив
        $fm = $row['fm'] ?? $row;
        $items = $fm[$fieldCode] ?? [];

        return array_filter(array_map(
            fn($item) => mb_strtolower(trim((string) ($item['VALUE'] ?? $item ?? ''))),
            (array) $items
        ));
    }

    /**
     * Извлекает значения многозначного поля из нормализованной строки
     *
     * @return string[]
     */
    private function extractNormalizedValues(array $row, string $fieldCode): array
    {
        $items = $row['fm'][$fieldCode] ?? [];

        return array_filter(array_map(
            fn($item) => mb_strtolower(trim((string) ($item['VALUE'] ?? ''))),
            (array) $items
        ));
    }

    /**
     * Ищет контакты по многозначному полю в CRM через ORM
     *
     * @param string   $fieldCode Код поля (PHONE или EMAIL)
     * @param string[] $values    Список значений для поиска
     *
     * @return array<string, int> значение => ID контакта
     */
    private function findContactsByMultiField(string $fieldCode, array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $result = [];

        // CRM хранит многозначные поля в связанной таблице через CommunicationTable
        // Используем \Bitrix\Crm\Integrity\DuplicateCommunicationCriterion или напрямую CommunicationTable
        $communicationResult = \Bitrix\Crm\FieldMultiTable::getList([
            'select' => ['ENTITY_ID', 'VALUE'],
            'filter' => [
                '=ENTITY_TYPE_ID' => \CCrmOwnerType::Contact,
                '=TYPE_ID' => $fieldCode,
                '=VALUE' => $values,
            ],
        ]);

        while ($row = $communicationResult->fetch()) {
            $val = mb_strtolower(trim((string) $row['VALUE']));
            if (!isset($result[$val])) {
                $result[$val] = (int) $row['ENTITY_ID'];
            }
        }

        return $result;
    }
}
