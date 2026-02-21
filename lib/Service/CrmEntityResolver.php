<?php

namespace Rwb\Massops\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

/**
 * Сервис проверки существования CRM-сущностей
 *
 * Валидирует что указанный ID сущности (компания, контакт, сделка и т.д.)
 * действительно существует в CRM.
 *
 * Использует современный Bitrix CRM Service Container / Factory
 * вместо устаревшего C*-API.
 *
 * Результаты кэшируются на время жизни объекта.
 */
class CrmEntityResolver
{
    /** @var array<string, array<int, bool>> */
    private array $cache = [];

    /**
     * Маппинг типа поля → числовой код типа сущности CRM.
     *
     * Используем числовые литералы вместо \CCrmOwnerType::X,
     * чтобы избежать ошибки "Class not found" до загрузки модуля crm.
     * Значения стабильны и не меняются между версиями Bitrix:
     *   Lead = 1, Deal = 2, Contact = 3, Company = 4
     */
    private const TYPE_OWNER_MAP = [
        'crm_company' => 4, // CCrmOwnerType::Company
        'crm_contact' => 3, // CCrmOwnerType::Contact
        'crm_deal'    => 2, // CCrmOwnerType::Deal
        'crm_lead'    => 1, // CCrmOwnerType::Lead
    ];

    private const TYPE_LABELS = [
        'crm_company' => 'Компания',
        'crm_contact' => 'Контакт',
        'crm_deal'    => 'Сделка',
        'crm_lead'    => 'Лид',
    ];

    /**
     * Проверяет существование CRM-сущности по ID
     *
     * @param int    $id        ID сущности
     * @param string $fieldType Тип поля (crm_company, crm_contact и т.д.)
     * @return bool
     */
    public function exists(int $id, string $fieldType): bool
    {
        if (isset($this->cache[$fieldType][$id])) {
            return $this->cache[$fieldType][$id];
        }

        Loader::requireModule('crm');

        $ownerTypeId = self::TYPE_OWNER_MAP[$fieldType] ?? null;
        if ($ownerTypeId === null) {
            // Неизвестный тип — пропускаем проверку
            return true;
        }

        $factory = Container::getInstance()->getFactory($ownerTypeId);
        $exists = ($factory !== null && $factory->getItem($id) !== null);

        $this->cache[$fieldType][$id] = $exists;

        return $exists;
    }

    /**
     * Возвращает человекочитаемое название типа сущности
     */
    public function getTypeLabel(string $fieldType): string
    {
        return self::TYPE_LABELS[$fieldType] ?? 'Сущность CRM';
    }

    /**
     * Проверяет поддерживается ли тип для валидации
     */
    public function supportsType(string $fieldType): bool
    {
        return isset(self::TYPE_OWNER_MAP[$fieldType]);
    }
}
