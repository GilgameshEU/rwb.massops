<?php

namespace Rwb\Massops\Import;

/**
 * Конфигурация импорта
 */
class Config
{
    /**
     * Возвращает конфигурацию мультиполей
     *
     * @return array<string, array{delimiter: string}>
     */
    public function getMultiFields(): array
    {
        return [
            'PHONE' => [
                'delimiter' => ',',
            ],
            'EMAIL' => [
                'delimiter' => ',',
            ],
        ];
    }
}
