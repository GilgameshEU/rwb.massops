<?php

namespace Rwb\Massops\Import;

class MultiValue
{
    public function __construct(
        protected string $value,
        protected string $type = 'WORK'
    ) {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
