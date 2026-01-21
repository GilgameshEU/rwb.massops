<?php

namespace Rwb\Massops\Import\Service;

use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ValidationResult;
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

    /**
     * Выполняет валидацию строки импорта компании
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

        if (empty($fields['TITLE'])) {
            $result->addError(
                new ImportError(
                    type: 'field',
                    code: 'REQUIRED',
                    message: 'Не заполнено обязательное поле: Название компании',
                    field: 'TITLE'
                )
            );
        }

        return $result;
    }
}
