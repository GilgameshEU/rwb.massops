<?php

namespace Rwb\Massops\Import\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;

class Xlsx extends AParser
{
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        return $spreadsheet
            ->getActiveSheet()
            ->toArray(null, true, true, false);
    }
}
