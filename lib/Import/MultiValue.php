<?php

namespace Rwb\Massops\Import;

/**
 * Значение мультиполя CRM
 */
class MultiValue
{
    /**
     * @param string $value Значение поля
     * @param string $type  Тип значения (например: WORK)
     */
    public function __construct(
        protected string $value,
        protected string $type = 'WORK'
    ) {
    }

    /**
     * Возвращает значение мультиполя
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Возвращает тип мультиполя
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
}
