<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\Loader;

/**
 * Сервис проверки существования CRM-сущностей
 *
 * Валидирует что указанный ID сущности (компания, контакт, сделка и т.д.)
 * действительно существует в CRM.
 *
 * Результаты кэшируются на время жизни объекта.
 */
class CrmEntityResolver
{
    /** @var array<string, array<int, bool>> */
    private array $cache = [];

    private const TYPE_CLASS_MAP = [
        'crm_company' => 'CCrmCompany',
        'crm_contact' => 'CCrmContact',
        'crm_deal' => 'CCrmDeal',
        'crm_lead' => 'CCrmLead',
    ];

    private const TYPE_LABELS = [
        'crm_company' => 'Компания',
        'crm_contact' => 'Контакт',
        'crm_deal' => 'Сделка',
        'crm_lead' => 'Лид',
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

        $className = self::TYPE_CLASS_MAP[$fieldType] ?? null;
        if ($className === null) {
            return true;
        }

        $entity = $className::getByID($id);
        $exists = ($entity !== false && !empty($entity));

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
        return isset(self::TYPE_CLASS_MAP[$fieldType]);
    }
}
