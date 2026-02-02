<?php

namespace Rwb\Massops\Import;

/**
 * Сервис импорта контактов
 *
 * Расширяет базовый ImportService кастомной валидацией:
 * контакт должен иметь имя или телефон/email.
 */
class ContactImportService extends ImportService
{
    /**
     * Выполняет валидацию строки импорта контакта
     */
    protected function validateRow(array $fields, array $uf, array $fm): ValidationResult
    {
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
