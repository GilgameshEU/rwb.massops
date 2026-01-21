<?php

namespace Rwb\Massops\Import;

/**
 * Объект ошибки импорта
 */
final class ImportError
{
    /**
     * @param string $type       Тип ошибки (file | header | row | field | system)
     * @param string $code       Код ошибки (REQUIRED | INVALID | NOT_FOUND | FILE_INVALID)
     * @param string $message    Текст ошибки
     * @param int|null $row      Номер строки файла
     * @param string|null $field Код поля
     * @param array $context     Дополнительный контекст
     */
    public function __construct(
        public readonly string $type,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $row = null,
        public readonly ?string $field = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * Преобразует ошибку в массив
     *
     * @return array{
     *     type: string,
     *     code: string,
     *     message: string,
     *     row: int|null,
     *     field: string|null,
     *     context: array
     * }
     */
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
