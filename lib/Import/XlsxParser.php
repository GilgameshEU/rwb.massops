<?php

namespace Rwb\Massops\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxParser implements ParserInterface
{
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        return $spreadsheet->getActiveSheet()->toArray();
    }
}
