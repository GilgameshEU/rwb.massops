<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Import\ValidationResult;
use Rwb\Massops\Repository\CRM\Deal;
use Rwb\Massops\Import\RowNormalizer;

class DealImport extends AImport
{
    public function __construct(Deal $repository)
    {
        parent::__construct(
            $repository,
            new RowNormalizer()
        );
    }

    protected function validateRow(
        array $fields,
        array $uf,
        array $fm
    ): ValidationResult {
        $result = new ValidationResult();

        if (empty($fields['TITLE'])) {
            $result->addError('Не заполнено поле TITLE');
        }

        if (empty($fields['STAGE_ID'])) {
            $result->addError('Не заполнена стадия сделки');
        }

        return $result;
    }
}
