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
     * @param array $rows Массив строк данных
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

        $columns = [];
        foreach ($headerRow as $i => $name) {
            $columns[] = [
                'id' => 'COL_' . $i,
                'name' => (string) $name,
                'default' => true,
            ];
        }

        $gridRows = [];
        foreach ($rows as $rowIndex => $row) {
            $data = [];

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
}
