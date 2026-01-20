<?php

namespace Rwb\Massops\Import;

use Rwb\Massops\Import\Parser\AParser;

class ImportRowNormalizer
{
    private AParser $parser;

    public function __construct()
    {
        $this->parser = new class extends AParser {
            public function parse(string $path): array
            {
                return [];
            }
        };
    }

    public function normalize(array $row, array $headers): array
    {
        $fields = [];
        $uf = [];
        $fm = [];

        foreach ($row as $index => $value) {
            $fieldCode = $headers[$index] ?? null;
            if (!$fieldCode || $value === '') {
                continue;
            }

            if (in_array($fieldCode, ['PHONE', 'EMAIL'], true)) {
                foreach ($this->parser->parseMultiField($value, $fieldCode) as $multi) {
                    $fm[$fieldCode][] = [
                        'VALUE' => $multi->getValue(),
                        'VALUE_TYPE' => $multi->getType(),
                    ];
                }
                continue;
            }

            if (str_starts_with($fieldCode, 'UF_')) {
                $uf[$fieldCode] = $value;
                continue;
            }

            $fields[$fieldCode] = $value;
        }

        return [$fields, $uf, $fm];
    }
}
