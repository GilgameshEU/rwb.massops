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
     * Нормализует строку данных
     *
     * @param array $row        Данные строки
     * @param array $fieldCodes Коды полей CRM
     *
     * @return NormalizeResult
     */
    public function normalize(array $row, array $fieldCodes): NormalizeResult
    {
        $fields = [];
        $uf = [];
        $fm = [];
        $errors = [];

        $multiFields = $this->config->getMultiFields();

        foreach ($fieldCodes as $index => $code) {
            $value = trim((string) ($row[$index] ?? ''));

            if ($value === '') {
                continue;
            }

            // мультиполя (PHONE / EMAIL)
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

            // пользовательские поля
            if (str_starts_with($code, 'UF_')) {
                $uf[$code] = $value;
                continue;
            }

            // обычные поля
            $fields[$code] = $value;
        }

        return new NormalizeResult($fields, $uf, $fm, $errors);
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
        // Защита от пустого разделителя
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

            // Нормализация телефонов в E.164
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

        // Если Bitrix-парсер распознал — используем E.164
        if ($phoneNumber->isValid()) {
            return $phoneNumber->format(Format::E164);
        }

        // Фолбэк: принимаем номер если базовый формат корректный
        // (от 10 до 15 цифр с ведущим +, стандарт E.164)
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
        // Убираем всё кроме цифр и +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Если уже начинается с + — вернуть как есть
        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        // 11-значные российские номера: 7... или 8...
        if (strlen($cleaned) === 11 && ($cleaned[0] === '7' || $cleaned[0] === '8')) {
            return '+7' . substr($cleaned, 1);
        }

        // 10-значный номер без кода страны — предполагаем Россию
        if (strlen($cleaned) === 10) {
            return '+7' . $cleaned;
        }

        return $cleaned;
    }
}
