<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\Config\Option;

/**
 * Конфигурация импорта
 *
 * Считывает настройки из опций модуля rwb.massops,
 * с фолбэком на значения по умолчанию.
 */
class Config
{
    private const MODULE_ID = 'rwb.massops';

    /**
     * Возвращает конфигурацию мультиполей
     *
     * @return array<string, array{delimiter: string, type: string}>
     */
    public function getMultiFields(): array
    {
        $delimiter = Option::get(self::MODULE_ID, 'multifield_delimiter', ',');

        return [
            'PHONE' => [
                'delimiter' => $delimiter,
                'type' => 'phone',
            ],
            'EMAIL' => [
                'delimiter' => $delimiter,
                'type' => 'email',
            ],
        ];
    }

    /**
     * Возвращает код страны по умолчанию для парсинга телефонов
     *
     * @return string ISO 3166-1 alpha-2 код
     */
    public function getPhoneDefaultCountry(): string
    {
        return Option::get(self::MODULE_ID, 'phone_default_country', 'RU');
    }
}
