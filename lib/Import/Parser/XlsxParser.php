<?php

namespace Rwb\Massops\Import\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Парсер XLSX-файлов
 */
class XlsxParser implements ParserInterface
{
    /**
     * Читает XLSX-файл и возвращает данные активного листа
     *
     * @param string $path Путь к XLSX-файлу
     *
     * @return array Массив строк активного листа
     */
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        return $spreadsheet
            ->getActiveSheet()
            ->toArray(null, true, true, false);
    }
}
