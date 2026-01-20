<?php

namespace Rwb\Massops\Import;

use RuntimeException;
use Rwb\Massops\Repository\CRM\AbstractCrmRepository;

class FieldValidator
{
    public function validate(array $rows, AbstractCrmRepository $repository): void
    {
        $this->assertRowsNotEmpty($rows);
        $header = $this->extractHeader($rows);
        $this->assertHeaderNotEmpty($header);
        $this->assertFieldsExist($header, $repository);
    }

    private function assertRowsNotEmpty(array $rows): void
    {
        if (empty($rows)) {
            throw new RuntimeException('Файл пустой');
        }

        if (count($rows) < 2) {
            throw new RuntimeException('Файл содержит только шаблон без данных');
        }
    }

    private function extractHeader(array $rows): array
    {
        return array_map('trim', (array) $rows[0]);
    }

    private function assertHeaderNotEmpty(array $header): void
    {
        if (empty(array_filter($header))) {
            throw new RuntimeException('Шаблон не содержит ни одного поля');
        }
    }

    private function assertFieldsExist(
        array $header,
        AbstractCrmRepository $repository
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
