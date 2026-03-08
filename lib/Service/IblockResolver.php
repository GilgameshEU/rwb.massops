<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\Loader;

/**
 * Сервис резолюции элементов и разделов инфоблоков
 *
 * Определяет ID элемента/раздела инфоблока по значению из файла импорта:
 * - числовой ID -> проверка существования
 * - текстовое название -> поиск по NAME
 *
 * Результаты кэшируются на время жизни объекта.
 */
class IblockResolver
{
    /** @var array<int, array<string, int|false>> */
    private array $elementCache = [];

    /** @var array<int, array<string, int|false>> */
    private array $sectionCache = [];

    /** @var array<int, string> */
    private array $iblockTypeCache = [];

    private ?bool $moduleAvailable = null;

    /**
     * Резолюция значения в ID элемента инфоблока
     *
     * @param string $value    Значение из файла (ID или название)
     * @param int    $iblockId ID инфоблока
     * @return int|null ID элемента или null если не найден
     */
    public function resolveElement(string $value, int $iblockId): ?int
    {
        if (!$this->ensureModule()) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $cacheKey = mb_strtolower($value);

        if (isset($this->elementCache[$iblockId][$cacheKey])) {
            $cached = $this->elementCache[$iblockId][$cacheKey];
            return $cached !== false ? $cached : null;
        }

        if (ctype_digit($value)) {
            return $this->resolveElementById((int) $value, $iblockId);
        }

        return $this->resolveElementByName($value, $iblockId);
    }

    /**
     * Резолюция значения в ID раздела инфоблока
     *
     * @param string $value    Значение из файла (ID или название)
     * @param int    $iblockId ID инфоблока
     * @return int|null ID раздела или null если не найден
     */
    public function resolveSection(string $value, int $iblockId): ?int
    {
        if (!$this->ensureModule()) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $cacheKey = mb_strtolower($value);

        if (isset($this->sectionCache[$iblockId][$cacheKey])) {
            $cached = $this->sectionCache[$iblockId][$cacheKey];
            return $cached !== false ? $cached : null;
        }

        if (ctype_digit($value)) {
            return $this->resolveSectionById((int) $value, $iblockId);
        }

        return $this->resolveSectionByName($value, $iblockId);
    }

    /**
     * Проверяет существование элемента по ID
     */
    private function resolveElementById(int $id, int $iblockId): ?int
    {
        $cacheKey = (string) $id;

        $rsElement = \CIBlockElement::getList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $id, 'ACTIVE' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $exists = (bool) $rsElement->fetch();
        $this->elementCache[$iblockId][$cacheKey] = $exists ? $id : false;

        return $exists ? $id : null;
    }

    /**
     * Ищет элемент по названию
     */
    private function resolveElementByName(string $name, int $iblockId): ?int
    {
        $cacheKey = mb_strtolower($name);

        $rsElement = \CIBlockElement::getList(
            [],
            ['IBLOCK_ID' => $iblockId, 'NAME' => $name, 'ACTIVE' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $element = $rsElement->fetch();
        $elementId = $element ? (int) $element['ID'] : false;
        $this->elementCache[$iblockId][$cacheKey] = $elementId;

        return $elementId !== false ? $elementId : null;
    }

    /**
     * Проверяет существование раздела по ID
     */
    private function resolveSectionById(int $id, int $iblockId): ?int
    {
        $cacheKey = (string) $id;

        $rsSection = \CIBlockSection::getList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $id, 'ACTIVE' => 'Y'],
            false,
            ['ID'],
            ['nTopCount' => 1]
        );

        $exists = (bool) $rsSection->fetch();
        $this->sectionCache[$iblockId][$cacheKey] = $exists ? $id : false;

        return $exists ? $id : null;
    }

    /**
     * Ищет раздел по названию
     */
    private function resolveSectionByName(string $name, int $iblockId): ?int
    {
        $cacheKey = mb_strtolower($name);

        $rsSection = \CIBlockSection::getList(
            [],
            ['IBLOCK_ID' => $iblockId, 'NAME' => $name, 'ACTIVE' => 'Y'],
            false,
            ['ID'],
            ['nTopCount' => 1]
        );

        $section = $rsSection->fetch();
        $sectionId = $section ? (int) $section['ID'] : false;
        $this->sectionCache[$iblockId][$cacheKey] = $sectionId;

        return $sectionId !== false ? $sectionId : null;
    }

    /**
     * Возвращает IBLOCK_TYPE_ID инфоблока по его ID
     *
     * Результат кэшируется. Возвращает пустую строку если не найден.
     */
    public function getIblockTypeId(int $iblockId): string
    {
        if (isset($this->iblockTypeCache[$iblockId])) {
            return $this->iblockTypeCache[$iblockId];
        }

        if (!$this->ensureModule()) {
            return $this->iblockTypeCache[$iblockId] = '';
        }

        $rsIblock = \CIBlock::getList([], ['=ID' => $iblockId], false, ['nTopCount' => 1], ['IBLOCK_TYPE_ID']);
        $iblock = $rsIblock->fetch();

        return $this->iblockTypeCache[$iblockId] = (string) ($iblock['IBLOCK_TYPE_ID'] ?? '');
    }

    /**
     * Проверяет доступность модуля iblock
     */
    private function ensureModule(): bool
    {
        if ($this->moduleAvailable === null) {
            $this->moduleAvailable = Loader::includeModule('iblock');
        }

        return $this->moduleAvailable;
    }
}
