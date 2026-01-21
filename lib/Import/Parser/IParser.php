<?php

namespace Rwb\Massops\Import\Parser;

/**
 * Интерфейс парсера импортируемых файлов
 */
interface IParser
{
    /**
     * Парсит файл и возвращает данные в виде массива строк
     *
     * @param string $path Путь к файлу
     *
     * @return array
     */
    public function parse(string $path): array;
}
