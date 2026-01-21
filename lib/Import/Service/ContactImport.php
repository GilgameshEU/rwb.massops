<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ValidationResult;
use Rwb\Massops\Repository\CRM\Contact;
use Rwb\Massops\Import\RowNormalizer;

class ContactImport extends AImport
{
    public function __construct(Contact $repository)
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

        if (
            empty($fields['NAME'])
            && empty($fm['PHONE'])
            && empty($fm['EMAIL'])
        ) {
            $result->addError(new ImportError(
                type: 'field',
                code: 'REQUIRED',
                message: 'Контакт должен иметь имя или телефон / email',
                field: 'NAME'
            ));
        }

        return $result;
    }
}
