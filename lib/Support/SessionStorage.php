<?php

namespace Rwb\Massops\Support;

/**
 * Хранилище данных импорта в сессии
 */
class SessionStorage
{
    private const SESSION_KEY = 'RWB_MASSOPS_RESULT';

    /**
     * Время жизни данных сессии в секундах (2 часа).
     * По истечении этого срока данные считаются устаревшими и недоступными.
     */
    private const TTL_SECONDS = 7200;

    /**
     * Сохраняет данные грида в сессию
     *
     * @param array $columns Колонки грида
     * @param array $rows Строки грида
     * @param string|null $entityType Тип CRM-сущности (company, contact, deal)
     */
    public static function save(array $columns, array $rows, ?string $entityType = null): void
    {
        // Минимальная структурная проверка — гарантируем, что в сессию
        // попадают только валидные данные с ожидаемой формой.
        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row) || !array_key_exists('data', $row) || !is_array($row['data'])) {
                throw new \InvalidArgumentException(
                    "Строка #{$rowIndex} имеет некорректную структуру: ожидается массив с ключом 'data'"
                );
            }
        }

        $_SESSION[self::SESSION_KEY] = [
            'COLUMNS' => $columns,
            'ROWS' => $rows,
            'SAVED_AT' => time(),
        ];

        if ($entityType !== null) {
            $_SESSION[self::SESSION_KEY]['ENTITY_TYPE'] = $entityType;
        }
    }

    /**
     * Проверяет, не истёк ли TTL данных сессии
     *
     * @return bool true если данные свежие, false если устарели или SAVED_AT отсутствует
     */
    private static function isExpired(): bool
    {
        $savedAt = $_SESSION[self::SESSION_KEY]['SAVED_AT'] ?? null;

        if ($savedAt === null) {
            return false; // данные без метки времени (старый формат) — не считаем устаревшими
        }

        return (time() - (int) $savedAt) > self::TTL_SECONDS;
    }

    /**
     * Получает данные из сессии
     *
     * Возвращает null если данные отсутствуют или истёк TTL.
     *
     * @return array{COLUMNS: array, ROWS: array, ENTITY_TYPE: string|null}|null
     */
    public static function get(): ?array
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }

        if (self::isExpired()) {
            self::clear();

            return null;
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Получает колонки из сессии
     */
    public static function getColumns(): array
    {
        return $_SESSION[self::SESSION_KEY]['COLUMNS'] ?? [];
    }

    /**
     * Получает строки из сессии
     *
     * Возвращает пустой массив если данные отсутствуют или истёк TTL.
     */
    public static function getRows(): array
    {
        return self::get()['ROWS'] ?? [];
    }

    /**
     * Сохраняет тип CRM-сущности
     */
    public static function saveEntityType(string $entityType): void
    {
        $_SESSION[self::SESSION_KEY]['ENTITY_TYPE'] = $entityType;
    }

    /**
     * Получает тип CRM-сущности из сессии
     */
    public static function getEntityType(): ?string
    {
        return $_SESSION[self::SESSION_KEY]['ENTITY_TYPE'] ?? null;
    }

    /**
     * Очищает данные из сессии
     */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Проверяет наличие актуальных (не истёкших) данных в сессии
     */
    public static function hasData(): bool
    {
        $data = self::get();

        return $data !== null && !empty($data['ROWS']);
    }
}
