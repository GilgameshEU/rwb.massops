<?php

namespace Rwb\Massops\Import\Parser;

interface IParser
{
    public function parse(string $path): array;
}
