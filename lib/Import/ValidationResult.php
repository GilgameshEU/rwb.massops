<?php

namespace Rwb\Massops\Import;

class ValidationResult
{
    /** @var ImportError[] */
    private array $errors = [];

    public function addError(ImportError $error): void
    {
        $this->errors[] = $error;
    }

    public function addErrorString(string $message, ?string $field = null, ?int $row = null): void
    {
        $this->errors[] = new ImportError(
            type: 'field',
            code: 'INVALID',
            message: $message,
            row: $row,
            field: $field
        );
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * @return ImportError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
