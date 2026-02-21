<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\Loader;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ImportErrorCode;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Стратегия поиска дублей по ИНН компании
 *
 * Ищет дубликаты:
 * 1. Внутри загружаемого файла — по полю ИНН
 * 2. В существующей базе CRM — через ORM-запрос к компаниям
 */
class InnDuplicateStrategy implements DuplicateStrategy
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
     * @param string|null $innFieldCode Код поля ИНН (если null — ищется автоматически)
     */
    public function __construct(?string $innFieldCode = null)
    {
        $this->innFieldCode = $innFieldCode;
    }

    /**
     * Проверяет дублирование ИНН внутри файла
     *
     * Ожидает $rows как массив raw-строк из сессии (с ключом 'uf' или 'data').
     * Дополнительный контекст: ['innFieldCode' => string] — переопределить код поля ИНН.
     */
    public function checkFileInternalDuplicates(array $rows, array $extra = []): array
    {
        $innFieldCode = $extra['innFieldCode'] ?? $this->getInnFieldCode();
        if (!$innFieldCode) {
            return [];
        }

        $errors = [];
        $innToRows = [];

        foreach ($rows as $rowIndex => $row) {
            $inn = $this->extractInn($row, $innFieldCode);
            if (empty($inn)) {
                continue;
            }

            $inn = $this->normalizeInn($inn);
            $innToRows[$inn][] = $rowIndex;
        }

        foreach ($innToRows as $inn => $rowIndexes) {
            if (count($rowIndexes) > 1) {
                foreach ($rowIndexes as $rowIndex) {
                    $duplicateRows = array_diff($rowIndexes, [$rowIndex]);
                    $duplicateRowsHuman = array_map(fn($i) => $i + 1, $duplicateRows);

                    $errors[$rowIndex] = new ImportError(
                        type: 'duplicate',
                        code: ImportErrorCode::DuplicateInFile->value,
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
     * Проверяет дублирование ИНН в CRM
     *
     * Дополнительный контекст: ['innFieldCode' => string] — переопределить код поля ИНН.
     */
    public function checkCrmDuplicates(array $normalizedRows, array $validRowIndexes, array $extra = []): array
    {
        Loader::requireModule('crm');

        $innFieldCode = $extra['innFieldCode'] ?? $this->getInnFieldCode();
        if (!$innFieldCode || empty($validRowIndexes)) {
            return [];
        }

        $errors = [];
        $innToRow = [];

        foreach ($validRowIndexes as $rowIndex) {
            $row = $normalizedRows[$rowIndex] ?? null;
            if (!$row) {
                continue;
            }

            $inn = $row['uf'][$innFieldCode] ?? null;
            if (empty($inn)) {
                continue;
            }

            $inn = $this->normalizeInn((string) $inn);
            $innToRow[$inn] = $rowIndex;
        }

        if (empty($innToRow)) {
            return $errors;
        }

        $existingCompanies = $this->findCompaniesByInn(array_keys($innToRow), $innFieldCode);

        foreach ($existingCompanies as $inn => $companyId) {
            if (isset($innToRow[$inn])) {
                $rowIndex = $innToRow[$inn];
                $errors[$rowIndex] = new ImportError(
                    type: 'duplicate',
                    code: ImportErrorCode::DuplicateInCrm->value,
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
     * Возвращает код поля ИНН (с кэшированием)
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
        if (isset($row['uf'][$innFieldCode])) {
            return $row['uf'][$innFieldCode];
        }

        if (isset($row['data'])) {
            foreach ($row['data'] as $key => $value) {
                if ($key === $innFieldCode) {
                    return $value;
                }
            }
        }

        return $row[$innFieldCode] ?? null;
    }

    /**
     * Нормализует ИНН (убирает пробелы)
     */
    private function normalizeInn(string $inn): string
    {
        return trim(preg_replace('/\s+/', '', $inn));
    }

    /**
     * Ищет компании по ИНН в CRM через ORM
     *
     * @param string[] $innList      Список ИНН для поиска
     * @param string   $innFieldCode Код поля ИНН
     *
     * @return array<string, int> ИНН => ID компании
     */
    private function findCompaniesByInn(array $innList, string $innFieldCode): array
    {
        if (empty($innList)) {
            return [];
        }

        $result = [];

        $dbResult = \Bitrix\Crm\CompanyTable::getList([
            'select' => ['ID', $innFieldCode],
            'filter' => ['=' . $innFieldCode => $innList],
        ]);

        while ($company = $dbResult->fetch()) {
            $inn = $this->normalizeInn((string) ($company[$innFieldCode] ?? ''));
            if (!empty($inn)) {
                $result[$inn] = (int) $company['ID'];
            }
        }

        return $result;
    }
}
