<?php

namespace Rwb\Massops\Import;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use Rwb\Massops\Import\Parser\CsvParser;
use Rwb\Massops\Import\Parser\XlsxParser;

/**
 * Парсер файлов импорта
 *
 * Определяет формат по расширению, парсит данные и нормализует кодировку в UTF-8.
 */
final class FileParser
{
    /**
     * Парсит файл импорта
     *
     * @param string $path      Путь к файлу
     * @param string $extension Расширение файла (csv, xlsx)
     *
     * @return array{data: array, errors: ImportError[]}
     */
    public function parse(string $path, string $extension): array
    {
        $errors = [];

        try {
            $parser = match ($extension) {
                'csv' => new CsvParser(),
                'xlsx' => new XlsxParser(),
                default => throw new InvalidArgumentException('Unsupported format'),
            };

            $data = $this->normalizeUtf8(
                $parser->parse($path)
            );

            return [
                'data' => $data,
                'errors' => $errors,
            ];
        } catch (RuntimeException|InvalidArgumentException $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: $e->getMessage()
            );
        } catch (Exception $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: 'Ошибка при чтении файла: ' . $e->getMessage()
            );
        }

        return [
            'data' => [],
            'errors' => $errors,
        ];
    }

    /**
     * Приводит данные к UTF-8
     */
    private function normalizeUtf8(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (!is_string($value)) {
                return;
            }

            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding(
                    $value,
                    'UTF-8',
                    ['Windows-1251', 'ISO-8859-1']
                );
            }
        });

        return $data;
    }
}
