<?php

namespace Rwb\Massops\Service;

use Rwb\Massops\Import\ImportError;

/**
 * Интерфейс стратегии поиска дублей
 *
 * Реализации определяют конкретный алгоритм поиска дубликатов —
 * по ИНН, телефону, email и т.д.
 */
interface DuplicateStrategy
{
    /**
     * Проверяет дубликаты внутри загружаемого файла
     *
     * Вызывается в beforeProcessRows() до нормализации всех строк.
     * Позволяет выявить одинаковые записи внутри одного файла.
     *
     * @param array $rows   Строки данных (raw, как пришли из сессии)
     * @param array $extra  Дополнительный контекст (fieldCodes, options и т.п.)
     *
     * @return array<int, ImportError> Ошибки, индексированные по rowIndex
     */
    public function checkFileInternalDuplicates(array $rows, array $extra = []): array;

    /**
     * Проверяет дубликаты относительно существующих данных в CRM
     *
     * Вызывается в beforeBatchSave() после нормализации строк.
     * Позволяет выявить записи, уже присутствующие в базе.
     *
     * @param array $normalizedRows  Нормализованные строки [rowIndex => ['fields', 'uf', 'fm', ...]]
     * @param array $validRowIndexes Индексы строк, прошедших базовую валидацию
     * @param array $extra           Дополнительный контекст (options и т.п.)
     *
     * @return array<int, ImportError> Ошибки, индексированные по rowIndex
     */
    public function checkCrmDuplicates(array $normalizedRows, array $validRowIndexes, array $extra = []): array;

    /**
     * Возвращает код поля-ключа дублирования (ИНН, телефон, email и т.д.)
     *
     * Используется DuplicateChecker для получения кода поля без привязки
     * к конкретному классу стратегии (устраняет необходимость instanceof-проверок).
     * Возвращает null, если стратегия не основана на конкретном поле.
     */
    public function getKeyFieldCode(): ?string;
}
