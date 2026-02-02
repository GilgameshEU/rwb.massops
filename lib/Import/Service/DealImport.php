<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Repository\CRM\Deal;
use Rwb\Massops\Import\RowNormalizer;

/**
 * Сервис импорта сделок
 */
class DealImport extends AImport
{
    /**
     * @param Deal $repository Репозиторий сделок CRM
     */
    public function __construct(Deal $repository)
    {
        parent::__construct(
            $repository,
            new RowNormalizer()
        );
    }
}
