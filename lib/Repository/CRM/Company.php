<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

/**
 * Репозиторий компаний CRM
 */
class Company extends ARepository
{
    /**
     * Возвращает тип сущности "Компания"
     *
     * @return int
     */
    public function getType(): int
    {
        return CCrmOwnerType::Company;
    }

    /**
     * Возвращает имя сущности
     *
     * @return string
     */
    public function getName(): string
    {
        return CCrmOwnerType::CompanyName;
    }
}
