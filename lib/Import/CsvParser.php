<?php

namespace Rwb\Massops\Import;

class CsvParser implements ParserInterface
{
    public function parse(string $path): array
    {
        $rows = [];

        if (($handle = fopen($path, 'r')) === false) {
            throw new \RuntimeException('Cannot open CSV');
        }

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
