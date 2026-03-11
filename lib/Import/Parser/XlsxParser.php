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
        // setReadDataOnly(true) пропускает загрузку форматирования, стилей,
        // диаграмм и другой метаинформации. Для файла 10 МБ это снижает
        // потребление памяти в несколько раз — читаются только значения ячеек.
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

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
