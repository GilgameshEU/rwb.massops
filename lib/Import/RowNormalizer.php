<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\PhoneNumber\Format;
use Bitrix\Main\PhoneNumber\Parser;

/**
 * Нормализует строку импорта в структуры CRM
 */
class RowNormalizer
{
    private Config $config;

    /**
     * @param Config|null $config Конфигурация импорта
     */
    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    /**
     * Типы полей, содержащие дату
     */
    private const DATE_TYPES = ['date', 'Date'];
    private const DATETIME_TYPES = ['datetime', 'DateTime'];

    /**
     * Типы полей, для которых проверяется целочисленность
     */
    private const INTEGER_TYPES = ['integer', 'Integer'];

    /**
     * Типы полей, для которых проверяется числовой формат
     */
    private const DOUBLE_TYPES = ['double', 'Double'];

    /**
     * Типы полей, для которых проверяется формат Да/Нет
     */
    private const BOOLEAN_TYPES = ['boolean', 'Boolean'];

    /**
     * Допустимые значения для boolean-полей
     */
    private const BOOLEAN_ALLOWED = ['Y', 'N', '1', '0', 'y', 'n'];

    /**
     * Нормализует строку данных
     *
     * @param array $row            Данные строки
     * @param array $fieldCodes     Коды полей CRM
     * @param array $fieldTypes     Карта типов полей (fieldCode => typeId)
     * @param array $multipleFields Коды UF-полей с MULTIPLE=Y
     * @param array $enumMappings   Маппинг enum: ['FIELD' => ['Текст' => 'id', ...], ...]
     *
     * @return NormalizeResult
     */
    public function normalize(
        array $row,
        array $fieldCodes,
        array $fieldTypes = [],
        array $multipleFields = [],
        array $enumMappings = []
    ): NormalizeResult {
        $fields = [];
        $uf = [];
        $fm = [];
        $errors = [];

        $multiFields = $this->config->getMultiFields();

        foreach ($fieldCodes as $index => $code) {
            if ($code === '' || $code === null) {
                continue;
            }

            $value = trim((string) ($row[$index] ?? ''));

            if ($value === '') {
                continue;
            }

            if (isset($multiFields[$code])) {
                $multiResult = $this->normalizeMultiField(
                    $value,
                    $multiFields[$code]['delimiter'],
                    $multiFields[$code]['type'],
                    $code
                );

                $fm[$code] = $multiResult['values'];

                foreach ($multiResult['errors'] as $error) {
                    $errors[] = $error;
                }

                continue;
            }

            $fieldType = $fieldTypes[$code] ?? null;

            if ($fieldType !== null && $this->isDateType($fieldType)) {
                $dateError = $this->validateDateValue($value, $code, $fieldType);
                if ($dateError !== null) {
                    $errors[] = $dateError;
                    continue;
                }
            }

            if ($fieldType !== null && !$this->isDateType($fieldType)) {
                $typeError = $this->validateFieldType($value, $code, $fieldType);
                if ($typeError !== null) {
                    $errors[] = $typeError;
                    continue;
                }
            }

            if (str_starts_with($code, 'UF_')) {
                if (in_array($code, $multipleFields, true)) {
                    $resolvedValues = [];
                    $hasEnumError = false;

                    foreach (explode(',', $value) as $v) {
                        $resolved = $this->resolveEnumValue(trim($v), $code, $enumMappings);
                        if ($resolved instanceof ImportError) {
                            $errors[] = $resolved;
                            $hasEnumError = true;
                            break;
                        }
                        $resolvedValues[] = $resolved;
                    }

                    if (!$hasEnumError) {
                        $uf[$code] = $resolvedValues;
                    }
                } else {
                    $resolved = $this->resolveEnumValue($value, $code, $enumMappings);
                    if ($resolved instanceof ImportError) {
                        $errors[] = $resolved;
                    } else {
                        $uf[$code] = $resolved;
                    }
                }
                continue;
            }

            $resolved = $this->resolveEnumValue($value, $code, $enumMappings);
            if ($resolved instanceof ImportError) {
                $errors[] = $resolved;
            } else {
                $fields[$code] = $resolved;
            }
        }

        return new NormalizeResult($fields, $uf, $fm, $errors);
    }

    /**
     * Проверяет, является ли тип поля датой или датой-временем
     */
    private function isDateType(string $type): bool
    {
        return in_array($type, self::DATE_TYPES, true)
            || in_array($type, self::DATETIME_TYPES, true);
    }

    /**
     * Валидирует значение по типу поля
     *
     * @return ImportError|null Ошибка валидации или null если значение корректно
     */
    private function validateFieldType(string $value, string $fieldCode, string $fieldType): ?ImportError
    {
        if (in_array($fieldType, self::INTEGER_TYPES, true)) {
            return $this->validateIntegerValue($value, $fieldCode);
        }

        if (in_array($fieldType, self::DOUBLE_TYPES, true)) {
            return $this->validateDoubleValue($value, $fieldCode);
        }

        if (in_array($fieldType, self::BOOLEAN_TYPES, true)) {
            return $this->validateBooleanValue($value, $fieldCode);
        }

        if ($fieldType === 'url') {
            return $this->validateUrlValue($value, $fieldCode);
        }

        if ($fieldType === 'money') {
            return $this->validateMoneyValue($value, $fieldCode);
        }

        return null;
    }

    /**
     * Валидирует целочисленное значение
     */
    private function validateIntegerValue(string $value, string $fieldCode): ?ImportError
    {
        if (!preg_match('/^-?\d+$/', $value)) {
            return new ImportError(
                type: 'field',
                code: ImportErrorCode::InvalidInteger->value,
                message: "Значение «{$value}» не является целым числом",
                field: $fieldCode
            );
        }

        return null;
    }

    /**
     * Валидирует числовое значение (с плавающей точкой)
     */
    private function validateDoubleValue(string $value, string $fieldCode): ?ImportError
    {
        $normalized = str_replace(',', '.', $value);

        if (!is_numeric($normalized)) {
            return new ImportError(
                type: 'field',
                code: ImportErrorCode::InvalidDouble->value,
                message: "Значение «{$value}» не является числом",
                field: $fieldCode
            );
        }

        return null;
    }

    /**
     * Валидирует значение поля Да/Нет
     */
    private function validateBooleanValue(string $value, string $fieldCode): ?ImportError
    {
        if (!in_array($value, self::BOOLEAN_ALLOWED, true)) {
            return new ImportError(
                type: 'field',
                code: ImportErrorCode::InvalidBoolean->value,
                message: "Значение «{$value}» недопустимо для поля Да/Нет. Ожидается: Y, N, 1 или 0",
                field: $fieldCode
            );
        }

        return null;
    }

    /**
     * Валидирует URL
     */
    private function validateUrlValue(string $value, string $fieldCode): ?ImportError
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (preg_match('#^https?://.+#i', $value)) {
            return null;
        }

        return new ImportError(
            type: 'field',
            code: ImportErrorCode::InvalidUrl->value,
            message: "Значение «{$value}» не является корректным URL-адресом",
            field: $fieldCode
        );
    }

    /**
     * Валидирует денежное значение
     *
     * Формат Bitrix: "1500.00" или "1500.00|RUB"
     */
    private function validateMoneyValue(string $value, string $fieldCode): ?ImportError
    {
        $parts = explode('|', $value, 2);
        $amount = str_replace(',', '.', trim($parts[0]));

        if (!is_numeric($amount)) {
            return new ImportError(
                type: 'field',
                code: ImportErrorCode::InvalidMoney->value,
                message: "Значение «{$value}» не является корректной суммой",
                field: $fieldCode
            );
        }

        return null;
    }

    /**
     * Резолвит текстовое значение enum-поля в ID
     *
     * Если для поля есть маппинг и текст найден — возвращает ID.
     * Если маппинг есть, но значение не найдено — возвращает ошибку.
     * Если маппинга нет — возвращает исходное значение.
     *
     * @return string|ImportError
     */
    private function resolveEnumValue(string $value, string $code, array $enumMappings): string|ImportError
    {
        if (!isset($enumMappings[$code])) {
            return $value;
        }

        if (isset($enumMappings[$code][$value])) {
            return (string) $enumMappings[$code][$value];
        }

        $validIds = array_values($enumMappings[$code]);
        if (in_array($value, $validIds, true)) {
            return $value;
        }

        $allowedValues = array_keys($enumMappings[$code]);
        $preview = implode(', ', array_slice($allowedValues, 0, 5));
        if (count($allowedValues) > 5) {
            $preview .= '...';
        }

        return new ImportError(
            type: 'field',
            code: ImportErrorCode::InvalidEnum->value,
            message: "Значение «{$value}» не найдено в списке допустимых. Допустимые: {$preview}",
            field: $code
        );
    }

    /**
     * Валидирует значение даты/времени
     *
     * @return ImportError|null Ошибка валидации или null если значение корректно
     */
    private function validateDateValue(string $value, string $fieldCode, string $fieldType): ?ImportError
    {
        if (in_array($fieldType, self::DATETIME_TYPES, true)) {
            $parsed = \DateTime::createFromFormat('d.m.Y H:i:s', $value);
            if ($parsed === false || $parsed->format('d.m.Y H:i:s') !== $value) {
                return new ImportError(
                    type: 'field',
                    code: ImportErrorCode::InvalidDatetime->value,
                    message: 'Неверный формат даты и времени: ' . $value . '. Ожидается ДД.ММ.ГГГГ ЧЧ:ММ:СС',
                    field: $fieldCode
                );
            }
            return null;
        }

        $parsed = \DateTime::createFromFormat('d.m.Y', $value);
        if ($parsed === false || $parsed->format('d.m.Y') !== $value) {
            return new ImportError(
                type: 'field',
                code: ImportErrorCode::InvalidDate->value,
                message: 'Неверный формат даты: ' . $value . '. Ожидается ДД.ММ.ГГГГ',
                field: $fieldCode
            );
        }

        return null;
    }

    /**
     * Нормализует значение мультиполя
     *
     * @param string $value     Сырое значение
     * @param string $delimiter Разделитель
     * @param string $type      Тип мультиполя (phone, email)
     * @param string $fieldCode Код поля (PHONE, EMAIL)
     *
     * @return array{values: array, errors: ImportError[]}
     */
    private function normalizeMultiField(
        string $value,
        string $delimiter,
        string $type,
        string $fieldCode,
    ): array {
        if ($delimiter === '') {
            $delimiter = ',';
        }

        $items = array_map(
            'trim',
            explode($delimiter, $value)
        );

        $values = [];
        $errors = [];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            if ($type === 'phone') {
                $normalized = $this->normalizePhone($item);

                if ($normalized === null) {
                    $errors[] = new ImportError(
                        type: 'field',
                        code: ImportErrorCode::InvalidPhone->value,
                        message: 'Неверный формат телефона: ' . $item,
                        field: $fieldCode
                    );
                    continue;
                }

                $item = $normalized;
            }

            $values[] = [
                'VALUE' => $item,
                'VALUE_TYPE' => 'WORK',
            ];
        }

        return [
            'values' => $values,
            'errors' => $errors,
        ];
    }

    /**
     * Нормализует номер телефона в формат E.164
     */
    private function normalizePhone(string $phone): ?string
    {
        $prepared = $this->preparePhone($phone);

        $defaultCountry = $this->config->getPhoneDefaultCountry();

        $parser = Parser::getInstance();
        $phoneNumber = $parser->parse($prepared, $defaultCountry);

        if ($phoneNumber->isValid()) {
            return $phoneNumber->format(Format::E164);
        }

        $digits = preg_replace('/\D/', '', $prepared);
        $withPlus = str_starts_with($prepared, '+') ? '+' . $digits : $prepared;

        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return str_starts_with($withPlus, '+') ? $withPlus : '+' . $digits;
        }

        return null;
    }

    /**
     * Подготавливает сырой номер телефона для парсера
     */
    private function preparePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        if (strlen($cleaned) === 11 && ($cleaned[0] === '7' || $cleaned[0] === '8')) {
            return '+7' . substr($cleaned, 1);
        }

        if (strlen($cleaned) === 10) {
            return '+7' . $cleaned;
        }

        return $cleaned;
    }
}
