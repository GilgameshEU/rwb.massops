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
                    continue; // Не добавляем невалидное значение
                }
            }

            if (str_starts_with($code, 'UF_')) {
                if (in_array($code, $multipleFields, true)) {
                    $uf[$code] = array_map(
                        fn(string $v) => $this->resolveEnumValue(trim($v), $code, $enumMappings),
                        explode(',', $value)
                    );
                } else {
                    $uf[$code] = $this->resolveEnumValue($value, $code, $enumMappings);
                }
                continue;
            }

            $fields[$code] = $this->resolveEnumValue($value, $code, $enumMappings);
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
     * Резолвит текстовое значение enum-поля в ID/XML_ID
     *
     * Если для поля есть маппинг и текст найден — возвращает ID.
     * Иначе возвращает исходное значение без изменений.
     */
    private function resolveEnumValue(string $value, string $code, array $enumMappings): string
    {
        if (isset($enumMappings[$code][$value])) {
            return (string) $enumMappings[$code][$value];
        }

        return $value;
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
                    code: 'INVALID_DATETIME',
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
                code: 'INVALID_DATE',
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
                        code: 'INVALID_PHONE',
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
     *
     * Подготавливает сырой номер перед парсингом:
     * - очищает от всех символов кроме цифр и +
     * - для 11-значных российских номеров (7.../8...) добавляет +7
     *
     * Если Bitrix-парсер считает номер валидным — форматируем через него.
     * Иначе проверяем базовый формат (длина, структура) и принимаем как есть.
     * Это позволяет импортировать номера с нестандартными кодами зон.
     *
     * @param string $phone Сырой номер телефона
     *
     * @return string|null Нормализованный номер или null если невалидный
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
     *
     * @param string $phone Сырой номер
     *
     * @return string Подготовленный номер
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
