<?php

namespace Rwb\Massops\Import;

/**
 * Результат валидации строки импорта
 */
class ValidationResult
{
    /** @var ImportError[] */
    private array $errors = [];

    /**
     * Добавляет ошибку валидации
     *
     * @param ImportError $error
     */
    public function addError(ImportError $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Проверяет наличие ошибок
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Возвращает список ошибок
     *
     * @return ImportError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
