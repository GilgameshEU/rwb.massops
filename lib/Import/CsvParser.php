<?php

namespace Rwb\Massops\Import;

class CsvParser implements ParserInterface
{
    public function parse(string $path): array
    {
        $rows = [];
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Cannot read CSV file');
        }

        // Убираем BOM и нормализуем концы строк
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Если вся строка в кавычках типа "A;B;C", убираем их перед парсингом
            if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                $line = mb_substr($line, 1, -1);
            }

            // Пробуем точку с запятой, если её нет — запятую
            $separator = (str_contains($line, ';')) ? ';' : ',';

            // Используем str_getcsv для корректной обработки полей
            $row = str_getcsv($line, $separator);

            // Финальная очистка каждого поля
            $row = array_map(function ($item) {
                return trim($item, " \t\n\r\0\x0B\"");
            }, $row);

            if (!empty($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
