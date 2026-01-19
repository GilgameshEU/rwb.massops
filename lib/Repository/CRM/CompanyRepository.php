<?php

namespace Rwb\Massops\Repository\CRM;

use CCrmOwnerType;

class CompanyRepository extends AbstractCrmRepository
{
    public function getType(): int
    {
        return CCrmOwnerType::Company;
    }

    public function getName(): string
    {
        return CCrmOwnerType::CompanyName;
    }
}
