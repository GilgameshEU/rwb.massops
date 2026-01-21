<?php

namespace Rwb\Massops\Component\Helper;

class SessionStorage
{
    private const SESSION_KEY = 'RWB_MASSOPS_RESULT';

    /**
     * Сохраняет данные грида в сессию
     *
     * @param array $columns Колонки грида
     * @param array $rows Строки грида
     */
    public static function save(array $columns, array $rows): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'COLUMNS' => $columns,
            'ROWS' => $rows,
        ];
    }

    /**
     * Получает данные из сессии
     *
     * @return array{COLUMNS: array, ROWS: array}|null
     */
    public static function get(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Получает колонки из сессии
     *
     * @return array
     */
    public static function getColumns(): array
    {
        return $_SESSION[self::SESSION_KEY]['COLUMNS'] ?? [];
    }

    /**
     * Получает строки из сессии
     *
     * @return array
     */
    public static function getRows(): array
    {
        return $_SESSION[self::SESSION_KEY]['ROWS'] ?? [];
    }

    /**
     * Очищает данные из сессии
     */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Проверяет наличие данных в сессии
     *
     * @return bool
     */
    public static function hasData(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && !empty($_SESSION[self::SESSION_KEY]['ROWS']);
    }
}
