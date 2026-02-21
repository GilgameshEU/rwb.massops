<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\UserTable;

/**
 * Сервис резолюции пользователей
 *
 * Определяет ID пользователя Bitrix по значению из файла импорта:
 * - числовой ID → проверка существования
 * - "Имя Фамилия" / "Фамилия Имя" → поиск по базе
 *
 * Результаты кэшируются на время жизни объекта.
 * Использует современный Bitrix ORM (UserTable) вместо Legacy CUser.
 */
class UserResolver
{
    /**
     * Кэш проверки по ID: userId → exists (bool)
     * @var array<int, bool>
     */
    private array $idCache = [];

    /**
     * Кэш поиска по ФИО: "имя фамилия" (lowercase) → userId|false
     * @var array<string, int|false>
     */
    private array $nameCache = [];

    /**
     * Резолюция значения в ID пользователя
     *
     * @param string $value Значение из файла (ID или "Имя Фамилия")
     * @return int|null ID пользователя или null если не найден
     */
    public function resolve(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return $this->resolveById((int) $value);
        }

        return $this->resolveByName($value);
    }

    /**
     * Проверяет существование активного пользователя по ID
     */
    private function resolveById(int $id): ?int
    {
        if (isset($this->idCache[$id])) {
            return $this->idCache[$id] ? $id : null;
        }

        $result = UserTable::getList([
            'select' => ['ID', 'ACTIVE'],
            'filter' => ['=ID' => $id],
            'limit' => 1,
        ])->fetch();

        $exists = ($result !== false && ($result['ACTIVE'] ?? 'N') === 'Y');
        $this->idCache[$id] = $exists;

        return $exists ? $id : null;
    }

    /**
     * Ищет пользователя по ФИО
     *
     * Пробует оба порядка: "Имя Фамилия" и "Фамилия Имя"
     */
    private function resolveByName(string $fullName): ?int
    {
        $cacheKey = mb_strtolower($fullName);

        if (array_key_exists($cacheKey, $this->nameCache)) {
            return $this->nameCache[$cacheKey] ?: null;
        }

        $parts = preg_split('/\s+/', trim($fullName), 2);

        if (count($parts) !== 2) {
            $this->nameCache[$cacheKey] = false;
            return null;
        }

        $userId = $this->findUserByNameParts($parts[0], $parts[1]);

        if ($userId === null) {
            $userId = $this->findUserByNameParts($parts[1], $parts[0]);
        }

        $this->nameCache[$cacheKey] = $userId ?? false;

        return $userId;
    }

    /**
     * Поиск пользователя по имени и фамилии через Bitrix ORM
     */
    private function findUserByNameParts(string $firstName, string $lastName): ?int
    {
        $result = UserTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=NAME' => $firstName,
                '=LAST_NAME' => $lastName,
                '=ACTIVE' => 'Y',
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 1,
        ])->fetch();

        return $result ? (int) $result['ID'] : null;
    }
}
