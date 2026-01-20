<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

class Deal extends ARepository
{
    public function getType(): int
    {
        return CCrmOwnerType::Deal;
    }

    public function getName(): string
    {
        return CCrmOwnerType::DealName;
    }
}
