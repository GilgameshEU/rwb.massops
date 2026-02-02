<?php

namespace Rwb\Massops\Repository;

use CCrmOwnerType;

/**
 * Типы CRM-сущностей
 */
enum EntityType: string
{
    case Company = 'company';
    case Contact = 'contact';
    case Deal = 'deal';

    /**
     * Возвращает ID типа сущности CRM
     */
    public function crmTypeId(): int
    {
        return match ($this) {
            self::Company => CCrmOwnerType::Company,
            self::Contact => CCrmOwnerType::Contact,
            self::Deal => CCrmOwnerType::Deal,
        };
    }

    /**
     * Возвращает системное имя сущности CRM
     */
    public function crmName(): string
    {
        return match ($this) {
            self::Company => CCrmOwnerType::CompanyName,
            self::Contact => CCrmOwnerType::ContactName,
            self::Deal => CCrmOwnerType::DealName,
        };
    }

    /**
     * Возвращает человекочитаемое название сущности
     */
    public function title(): string
    {
        return match ($this) {
            self::Company => 'Компании',
            self::Contact => 'Контакты',
            self::Deal => 'Сделки',
        };
    }
}
