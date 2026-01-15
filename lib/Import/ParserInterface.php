<?php

namespace Rwb\Massops\Import;

interface ParserInterface
{
    public function parse(string $path): array;
}
