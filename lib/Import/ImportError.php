<?php

namespace Rwb\Massops\Import;

final class ImportError
{
    public function __construct(
        public readonly string $type,    // file | header | row | field | system
        public readonly string $code,    // REQUIRED | INVALID | NOT_FOUND | FILE_INVALID
        public readonly string $message,
        public readonly ?int $row = null,
        public readonly ?string $field = null,
        public readonly array $context = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'code' => $this->code,
            'message' => $this->message,
            'row' => $this->row,
            'field' => $this->field,
            'context' => $this->context,
        ];
    }
}
