<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ValidationResult;
use Rwb\Massops\Repository\CRM\Contact;
use Rwb\Massops\Import\RowNormalizer;

/**
 * Сервис импорта контактов
 */
class ContactImport extends AImport
{
    /**
     * @param Contact $repository Репозиторий контактов CRM
     */
    public function __construct(Contact $repository)
    {
        parent::__construct(
            $repository,
            new RowNormalizer()
        );
    }

    /**
     * Выполняет валидацию строки импорта контакта
     *
     * @param array $fields Основные поля
     * @param array $uf     Пользовательские поля
     * @param array $fm     Мультиполя
     *
     * @return ValidationResult
     */
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
            $result->addError(
                new ImportError(
                    type: 'field',
                    code: 'REQUIRED',
                    message: 'Контакт должен иметь имя или телефон / email',
                    field: 'NAME'
                )
            );
        }

        return $result;
    }
}
