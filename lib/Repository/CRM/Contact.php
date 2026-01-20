<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

class Contact extends ARepository
{
    public function getType(): int
    {
        return CCrmOwnerType::Contact;
    }

    public function getName(): string
    {
        return CCrmOwnerType::ContactName;
    }
}
