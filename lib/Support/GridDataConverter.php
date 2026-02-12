<?php

namespace Rwb\Massops\Support;

/**
 * Конвертер данных в формат Bitrix грида
 */
class GridDataConverter
{
    /**
     * Преобразует данные строк в формат для Bitrix грида
     *
     * @param array $rows           Массив строк данных
     * @param array|null $headerRow Первая строка (заголовки). Если null, будет взят первый элемент массива $rows
     *
     * @return array{columns: array, rows: array}
     */
    public static function convertToGridFormat(array $rows, ?array $headerRow = null): array
    {
        if (empty($rows)) {
            return ['columns' => [], 'rows' => []];
        }

        if ($headerRow === null) {
            $headerRow = array_shift($rows);
        }

        // Первая колонка — номер строки
        $columns = [
            [
                'id' => 'ROW_NUM',
                'name' => '№',
                'sort' => 'ROW_NUM',
                'default' => true,
                'width' => 50,
            ],
        ];

        foreach ($headerRow as $i => $name) {
            $columns[] = [
                'id' => 'COL_' . $i,
                'name' => (string) $name,
                'sort' => 'COL_' . $i,
                'default' => true,
            ];
        }

        $gridRows = [];
        foreach ($rows as $rowIndex => $row) {
            $data = [
                'ROW_NUM' => $rowIndex + 1, // Нумерация с 1
            ];

            foreach ($row as $cellIndex => $value) {
                $data['COL_' . $cellIndex] = (string) $value;
            }

            $gridRows[] = [
                'id' => 'row_' . $rowIndex,
                'data' => $data,
            ];
        }

        return [
            'columns' => $columns,
            'rows' => $gridRows,
        ];
    }

    /**
     * Сортирует строки грида
     *
     * @param array $rows       Строки грида
     * @param string $sortBy    ID колонки для сортировки
     * @param string $sortOrder Направление (ASC/DESC)
     *
     * @return array Отсортированные строки
     */
    public static function sortRows(array $rows, string $sortBy, string $sortOrder = 'ASC'): array
    {
        if (empty($rows) || empty($sortBy)) {
            return $rows;
        }

        usort($rows, function ($a, $b) use ($sortBy, $sortOrder) {
            $valueA = $a['data'][$sortBy] ?? '';
            $valueB = $b['data'][$sortBy] ?? '';

            // Пробуем числовое сравнение
            $numA = is_numeric($valueA) ? (float) $valueA : null;
            $numB = is_numeric($valueB) ? (float) $valueB : null;

            if ($numA !== null && $numB !== null) {
                $result = $numA <=> $numB;
            } else {
                // Строковое сравнение с учётом локали
                $result = strcasecmp($valueA, $valueB);
            }

            return strtoupper($sortOrder) === 'DESC' ? -$result : $result;
        });

        return $rows;
    }
}
