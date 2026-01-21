<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Import\ImportError;
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
            $result->addError(new ImportError(
                type: 'field',
                code: 'REQUIRED',
                message: 'Не заполнено поле TITLE',
                field: 'TITLE'
            ));
        }

        if (empty($fields['STAGE_ID'])) {
            $result->addError(new ImportError(
                type: 'field',
                code: 'REQUIRED',
                message: 'Не заполнена стадия сделки',
                field: 'STAGE_ID'
            ));
        }

        return $result;
    }
}
