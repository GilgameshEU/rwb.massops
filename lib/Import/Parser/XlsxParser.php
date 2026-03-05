<?php

namespace Rwb\Massops\Import\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Парсер XLSX-файлов
 */
class XlsxParser implements ParserInterface
{
    /**
     * Название листа с данными для импорта
     */
    public const IMPORT_SHEET_NAME = 'Импорт';

    /**
     * Читает XLSX-файл и возвращает данные листа «Импорт»
     *
     * @param string $path Путь к XLSX-файлу
     *
     * @return array Массив строк листа импорта
     *
     * @throws RuntimeException Если лист с нужным именем не найден
     */
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        $sheet = $spreadsheet->getSheetByName(self::IMPORT_SHEET_NAME);

        if ($sheet === null) {
            throw new RuntimeException(
                'В файле не найден лист «' . self::IMPORT_SHEET_NAME . '». '
                . 'Используйте шаблон, скачанный из системы, и не переименовывайте листы.'
            );
        }

        return $sheet->toArray(null, true, true, false);
    }
}
