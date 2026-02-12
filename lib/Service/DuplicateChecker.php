<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\Loader;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Сервис проверки дубликатов
 *
 * Проверяет дубликаты:
 * 1. Внутри загруженного файла
 * 2. В существующей базе CRM
 */
class DuplicateChecker
{
    /**
     * XML_ID поля ИНН
     */
    private const INN_XML_ID = 'INN';

    /**
     * Код поля ИНН (кэшируется)
     */
    private ?string $innFieldCode = null;

    /**
     * Проверяет дубликаты внутри файла
     *
     * @param array $rows Строки файла (после нормализации)
     * @param string $innFieldCode Код поля ИНН
     *
     * @return array<int, ImportError> Ошибки по индексам строк
     */
    public function checkFileInternalDuplicates(array $rows, string $innFieldCode): array
    {
        $errors = [];
        $innToRows = [];

        // Собираем ИНН по строкам
        foreach ($rows as $rowIndex => $row) {
            $inn = $this->extractInn($row, $innFieldCode);

            if (empty($inn)) {
                continue;
            }

            $inn = $this->normalizeInn($inn);

            if (!isset($innToRows[$inn])) {
                $innToRows[$inn] = [];
            }
            $innToRows[$inn][] = $rowIndex;
        }

        // Находим дубликаты (ИНН встречается более 1 раза)
        foreach ($innToRows as $inn => $rowIndexes) {
            if (count($rowIndexes) > 1) {
                // Помечаем ВСЕ строки с этим ИНН как дубликаты
                foreach ($rowIndexes as $rowIndex) {
                    $duplicateRows = array_diff($rowIndexes, [$rowIndex]);
                    $duplicateRowsHuman = array_map(fn($i) => $i + 1, $duplicateRows);

                    $errors[$rowIndex] = new ImportError(
                        type: 'duplicate',
                        code: 'DUPLICATE_IN_FILE',
                        message: 'Дубликат ИНН в файле (строки: ' . implode(', ', $duplicateRowsHuman) . ')',
                        row: $rowIndex + 1,
                        field: $innFieldCode,
                        context: [
                            'inn' => $inn,
                            'duplicateRows' => $duplicateRowsHuman,
                        ]
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Проверяет дубликаты в CRM
     *
     * @param array $rows Строки файла
     * @param string $innFieldCode Код поля ИНН
     * @param array $excludeRowIndexes Индексы строк для исключения (уже с ошибками)
     *
     * @return array<int, ImportError> Ошибки по индексам строк
     */
    public function checkCrmDuplicates(array $rows, string $innFieldCode, array $excludeRowIndexes = []): array
    {
        Loader::requireModule('crm');

        $errors = [];

        // Собираем ИНН из валидных строк
        $innToRow = [];
        foreach ($rows as $rowIndex => $row) {
            if (in_array($rowIndex, $excludeRowIndexes, true)) {
                continue;
            }

            $inn = $this->extractInn($row, $innFieldCode);
            if (empty($inn)) {
                continue;
            }

            $inn = $this->normalizeInn($inn);
            $innToRow[$inn] = $rowIndex;
        }

        if (empty($innToRow)) {
            return $errors;
        }

        // Ищем существующие компании с такими ИНН
        $existingCompanies = $this->findCompaniesByInn(array_keys($innToRow), $innFieldCode);

        foreach ($existingCompanies as $inn => $companyId) {
            if (isset($innToRow[$inn])) {
                $rowIndex = $innToRow[$inn];
                $errors[$rowIndex] = new ImportError(
                    type: 'duplicate',
                    code: 'DUPLICATE_IN_CRM',
                    message: 'Компания с таким ИНН уже существует в CRM (ID: ' . $companyId . ')',
                    row: $rowIndex + 1,
                    field: $innFieldCode,
                    context: [
                        'inn' => $inn,
                        'existingCompanyId' => $companyId,
                    ]
                );
            }
        }

        return $errors;
    }

    /**
     * Возвращает код поля ИНН для компании
     */
    public function getInnFieldCode(): ?string
    {
        if ($this->innFieldCode === null) {
            $this->innFieldCode = UserFieldHelper::getFieldCodeByXmlId('CRM_COMPANY', self::INN_XML_ID);
        }

        return $this->innFieldCode;
    }

    /**
     * Извлекает ИНН из строки данных
     */
    private function extractInn(array $row, string $innFieldCode): ?string
    {
        // Данные могут быть в разных форматах
        if (isset($row['uf'][$innFieldCode])) {
            return $row['uf'][$innFieldCode];
        }

        // Или в плоском формате data
        if (isset($row['data'])) {
            foreach ($row['data'] as $key => $value) {
                // Ищем по коду поля в маппинге
                if ($key === $innFieldCode) {
                    return $value;
                }
            }
        }

        // Прямой доступ
        if (isset($row[$innFieldCode])) {
            return $row[$innFieldCode];
        }

        return null;
    }

    /**
     * Нормализует ИНН (убирает пробелы, приводит к строке)
     */
    private function normalizeInn(string $inn): string
    {
        return trim(preg_replace('/\s+/', '', $inn));
    }

    /**
     * Ищет компании по ИНН в CRM
     *
     * @param array $innList Список ИНН для поиска
     * @param string $innFieldCode Код поля ИНН
     *
     * @return array<string, int> ИНН => ID компании
     */
    private function findCompaniesByInn(array $innList, string $innFieldCode): array
    {
        $result = [];

        if (empty($innList)) {
            return $result;
        }

        // Используем CCompany для поиска
        $filter = [
            'CHECK_PERMISSIONS' => 'N',
            $innFieldCode => $innList,
        ];

        $select = ['ID', $innFieldCode];

        $rsCompanies = \CCrmCompany::GetListEx(
            [],
            $filter,
            false,
            false,
            $select
        );

        while ($company = $rsCompanies->Fetch()) {
            $inn = $this->normalizeInn($company[$innFieldCode] ?? '');
            if (!empty($inn)) {
                $result[$inn] = (int) $company['ID'];
            }
        }

        return $result;
    }
}
