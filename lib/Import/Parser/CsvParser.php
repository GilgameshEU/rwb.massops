<?php

namespace Rwb\Massops\Import\Parser;

use RuntimeException;

/**
 * Парсер CSV-файлов
 */
class CsvParser implements ParserInterface
{
    /**
     * Читает CSV-файл и возвращает данные в виде массива строк
     *
     * @param string $path Путь к CSV-файлу
     *
     * @return array Массив строк файла
     * @throws RuntimeException Если файл невозможно прочитать
     */
    public function parse(string $path): array
    {
        $rows = [];
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read CSV file');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                $line = mb_substr($line, 1, -1);
            }

            $separator = str_contains($line, ';') ? ';' : ',';
            $row = str_getcsv($line, $separator);

            $rows[] = array_map(
                fn($item) => trim($item, " \t\n\r\0\x0B\""),
                $row
            );
        }

        return $rows;
    }
}
