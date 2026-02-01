<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Repository\CRM\Company;
use Rwb\Massops\Import\RowNormalizer;

/**
 * Сервис импорта компаний
 */
class CompanyImport extends AImport
{
    /**
     * @param Company $repository Репозиторий компаний CRM
     */
    public function __construct(Company $repository)
    {
        parent::__construct(
            $repository,
            new RowNormalizer()
        );
    }
}
