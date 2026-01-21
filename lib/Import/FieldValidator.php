<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\LoaderException;
use Rwb\Massops\Repository\CRM\ARepository;

class FieldValidator
{
    /**
     * Валидирует поля импортируемых данных
     *
     * @param array $rows             Массив строк данных для импорта
     * @param ARepository $repository Репозиторий для проверки существования полей
     *
     * @return ImportError[]
     * @throws LoaderException
     */
    public function validate(array $rows, ARepository $repository): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->assertRowsNotEmpty($rows));
        $header = $this->extractHeader($rows);
        $errors = array_merge($errors, $this->assertHeaderNotEmpty($header));

        return array_merge($errors, $this->assertFieldsExist($header, $repository));
    }

    /**
     * Проверяет, что массив строк не пустой
     *
     * @param array $rows Массив строк
     *
     * @return ImportError[]
     */
    private function assertRowsNotEmpty(array $rows): array
    {
        $errors = [];

        if (empty($rows)) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: 'Файл пустой'
            );

            return $errors;
        }

        if (count($rows) < 2) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: 'Файл содержит только шаблон без данных'
            );
        }

        return $errors;
    }

    /**
     * Извлекает заголовок из первой строки данных
     *
     * @param array $rows Массив строк
     *
     * @return array Массив заголовков
     */
    private function extractHeader(array $rows): array
    {
        return array_map('trim', (array) $rows[0]);
    }

    /**
     * Проверяет, что заголовок не пустой
     *
     * @param array $header Массив заголовков
     *
     * @return ImportError[]
     */
    private function assertHeaderNotEmpty(array $header): array
    {
        $errors = [];

        if (empty(array_filter($header))) {
            $errors[] = new ImportError(
                type: 'header',
                code: 'INVALID',
                message: 'Шаблон не содержит ни одного поля'
            );
        }

        return $errors;
    }

    /**
     * Проверяет существование полей в CRM
     *
     * @param array $header           Массив заголовков для проверки
     * @param ARepository $repository Репозиторий CRM для получения списка полей
     *
     * @return ImportError[]
     * @throws LoaderException
     */
    private function assertFieldsExist(
        array $header,
        ARepository $repository
    ): array {
        $errors = [];
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
            $errors[] = new ImportError(
                type: 'header',
                code: 'NOT_FOUND',
                message: 'В CRM не найдены поля: ' . implode(', ', $missing),
                context: ['missing_fields' => $missing]
            );
        }

        return $errors;
    }
}
