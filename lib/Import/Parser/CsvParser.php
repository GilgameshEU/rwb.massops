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

        // Определяем разделитель по первой (заголовочной) строке
        $separator = $this->detectSeparator($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                $line = mb_substr($line, 1, -1);
            }

            $row = str_getcsv($line, $separator);

            $rows[] = array_map(
                fn($item) => trim($item, " \t\n\r\0\x0B\""),
                $row
            );
        }

        return $rows;
    }

    /**
     * Определяет разделитель CSV по заголовочной строке
     *
     * Приоритет: точка с запятой (;), затем табуляция, затем запятая.
     * Заголовки CRM-полей не содержат запятых, поэтому анализ
     * первой строки даёт надёжный результат.
     *
     * @param array $lines Строки файла
     *
     * @return string Символ-разделитель
     */
    private function detectSeparator(array $lines): string
    {
        $headerLine = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $headerLine = $trimmed;
                break;
            }
        }

        if ($headerLine === '') {
            return ';';
        }

        // Считаем вхождения разделителей в заголовке
        $semicolons = substr_count($headerLine, ';');
        $commas = substr_count($headerLine, ',');
        $tabs = substr_count($headerLine, "\t");

        // Приоритет: ; > \t > ,
        if ($semicolons > 0 && $semicolons >= $commas) {
            return ';';
        }

        if ($tabs > 0 && $tabs >= $commas) {
            return "\t";
        }

        if ($commas > 0) {
            return ',';
        }

        // По умолчанию — точка с запятой (стандарт для RU-локали)
        return ';';
    }
}
