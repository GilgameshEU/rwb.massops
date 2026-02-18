<?php

namespace Rwb\Massops\Service;

/**
 * Сервис резолюции пользователей
 *
 * Определяет ID пользователя Bitrix по значению из файла импорта:
 * - числовой ID → проверка существования
 * - "Имя Фамилия" / "Фамилия Имя" → поиск по базе
 *
 * Результаты кэшируются на время жизни объекта.
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

        $user = \CUser::getByID($id)->fetch();
        $exists = ($user !== false && ($user['ACTIVE'] ?? 'N') === 'Y');
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
     * Поиск пользователя по имени и фамилии через Bitrix API
     */
    private function findUserByNameParts(string $firstName, string $lastName): ?int
    {
        $rsUsers = \CUser::getList(
            'ID',
            'ASC',
            [
                'NAME' => $firstName,
                'LAST_NAME' => $lastName,
                'ACTIVE' => 'Y',
            ],
            ['FIELDS' => ['ID']]
        );

        $user = $rsUsers->fetch();

        return $user ? (int) $user['ID'] : null;
    }
}
