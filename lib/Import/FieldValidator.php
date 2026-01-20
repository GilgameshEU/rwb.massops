<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\LoaderException;
use RuntimeException;
use Rwb\Massops\Repository\CRM\ARepository;

class FieldValidator
{
    /**
     * @throws LoaderException
     */
    public function validate(array $rows, ARepository $repository): void
    {
        $this->assertRowsNotEmpty($rows);
        $header = $this->extractHeader($rows);
        $this->assertHeaderNotEmpty($header);
        $this->assertFieldsExist($header, $repository);
    }

    /**
     * @param array $rows
     *
     * @return void
     */
    private function assertRowsNotEmpty(array $rows): void
    {
        if (empty($rows)) {
            throw new RuntimeException('Файл пустой');
        }

        if (count($rows) < 2) {
            throw new RuntimeException('Файл содержит только шаблон без данных');
        }
    }

    /**
     * @param array $rows
     *
     * @return array
     */
    private function extractHeader(array $rows): array
    {
        return array_map('trim', (array) $rows[0]);
    }

    /**
     * @param array $header
     *
     * @return void
     */
    private function assertHeaderNotEmpty(array $header): void
    {
        if (empty(array_filter($header))) {
            throw new RuntimeException('Шаблон не содержит ни одного поля');
        }
    }

    /**
     * @throws LoaderException
     */
    private function assertFieldsExist(
        array $header,
        ARepository $repository
    ): void {
        $crmTitles = array_values($repository->getFieldList());

        $missing = [];

        foreach ($header as $title) {
            if ($title === '') {
                continue;
            }

            if (!in_array($title, $crmTitles, true)) {
                $missing[] = $title;
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(
                'В CRM не найдены поля: ' . implode(', ', $missing)
            );
        }
    }
}
