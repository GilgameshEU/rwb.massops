<?php

namespace Rwb\Massops\Import;

class CompanyImportService
{
    public function parseFile(string $path, string $ext): array
    {
        $parser = match ($ext) {
            'csv' => new CsvParser(),
            'xlsx' => new XlsxParser(),
            default => throw new \InvalidArgumentException('Unsupported format'),
        };

        return $this->normalizeUtf8(
            $parser->parse($path)
        );
    }

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
