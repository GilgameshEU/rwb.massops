<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

/**
 * Репозиторий сделок CRM
 */
class Deal extends ARepository
{
    /**
     * Возвращает тип сущности "Сделка"
     *
     * @return int
     */
    public function getType(): int
    {
        return CCrmOwnerType::Deal;
    }

    /**
     * Возвращает имя сущности
     *
     * @return string
     */
    public function getName(): string
    {
        return CCrmOwnerType::DealName;
    }
}
