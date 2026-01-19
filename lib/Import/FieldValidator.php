<?php

namespace Rwb\Massops\Import;

use Rwb\Massops\Repository\CRM\AbstractCrmRepository;

class FieldValidator
{
    /**
     * Валидирует файл импорта:
     * - наличие заголовков
     * - наличие данных
     * - существование полей в CRM
     *
     * @param array $rows
     * @param AbstractCrmRepository $repository
     *
     * @throws \RuntimeException
     */
    public function validate(array $rows, AbstractCrmRepository $repository): void
    {
        if (empty($rows)) {
            throw new \RuntimeException('Файл пустой');
        }

        if (count($rows) < 2) {
            throw new \RuntimeException(
                'Файл содержит только шаблон без данных'
            );
        }

        $headerRow = array_map('trim', (array) $rows[0]);

        if (empty($headerRow)) {
            throw new \RuntimeException(
                'Шаблон не содержит ни одного поля'
            );
        }

        $this->validateFieldsExist($headerRow, $repository);
    }

    protected function validateFieldsExist(
        array $fileFieldTitles,
        AbstractCrmRepository $repository
    ): void {
        $crmFields = $repository->getFieldList();
        $crmFieldTitles = array_values($crmFields);

        $missingFields = [];

        foreach ($fileFieldTitles as $title) {
            if ($title === '') {
                continue;
            }

            if (!in_array($title, $crmFieldTitles, true)) {
                $missingFields[] = $title;
            }
        }

        if (!empty($missingFields)) {
            throw new \RuntimeException(
                'В CRM не найдены поля: ' . implode(', ', $missingFields)
            );
        }
    }
}
