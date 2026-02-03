<?php

namespace Rwb\Massops\Import;

/**
 * Результат нормализации строки импорта
 */
class NormalizeResult
{
    /**
     * @param array        $fields Основные поля CRM
     * @param array        $uf     Пользовательские поля (UF_*)
     * @param array        $fm     Мультиполя (PHONE, EMAIL)
     * @param ImportError[] $errors Ошибки нормализации (невалидные телефоны и т.д.)
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $uf,
        public readonly array $fm,
        public readonly array $errors = [],
    ) {
    }
}
