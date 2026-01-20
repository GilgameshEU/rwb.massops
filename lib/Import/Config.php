<?php

namespace Rwb\Massops\Import;

class Config
{
    /**
     * Конфигурация мультиполей
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
