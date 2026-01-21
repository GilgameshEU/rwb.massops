<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

/**
 * Репозиторий контактов CRM
 */
class Contact extends ARepository
{
    /**
     * Возвращает тип сущности "Контакт"
     *
     * @return int
     */
    public function getType(): int
    {
        return CCrmOwnerType::Contact;
    }

    /**
     * Возвращает имя сущности
     *
     * @return string
     */
    public function getName(): string
    {
        return CCrmOwnerType::ContactName;
    }
}
