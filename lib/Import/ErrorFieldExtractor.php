<?php

namespace Rwb\Massops\Import;

use Bitrix\Main\Error;

/**
 * Извлекает код поля из объекта ошибки Bitrix Result
 *
 * Стратегии поиска (по приоритету):
 * 1. Прямое совпадение Error::getCode() с кодом поля
 * 2. Код поля в суффиксе Error::getCode() (BX_CRM_REQUIRED_FIELD_TITLE → TITLE)
 * 3. Парсинг названия поля из текста ошибки (Поле "Название" → TITLE)
 */
final class ErrorFieldExtractor
{
    /** @var string[] */
    private readonly array $fieldCodes;

    /**
     * @param array<string, string> $fieldList Маппинг код => название
     */
    public function __construct(
        private readonly array $fieldList
    ) {
        $this->fieldCodes = array_keys($fieldList);
    }

    /**
     * Извлекает код поля из ошибки Bitrix
     *
     * @param Error $error Объект ошибки Bitrix
     *
     * @return string|null Код поля или null
     */
    public function extractFieldCode(Error $error): ?string
    {
        // 1. Поиск по Error::getCode()
        $code = (string) $error->getCode();

        if ($code !== '' && $code !== '0') {
            // Прямое совпадение
            if (in_array($code, $this->fieldCodes, true)) {
                return $code;
            }

            // Суффикс (BX_CRM_REQUIRED_FIELD_TITLE → TITLE)
            foreach ($this->fieldCodes as $fieldCode) {
                if (str_ends_with($code, '_' . $fieldCode)) {
                    return $fieldCode;
                }
            }
        }

        // 2. Парсинг названия поля из текста сообщения
        //    Формат Bitrix: Поле "Название" обязательно для заполнения
        $message = $error->getMessage();

        if (preg_match('/[«""\'](.+?)[»""\']/', $message, $matches)) {
            $fieldTitle = $matches[1];

            // название → код (Название → TITLE)
            $flipped = array_flip($this->fieldList);
            if (isset($flipped[$fieldTitle])) {
                return $flipped[$fieldTitle];
            }
        }

        return null;
    }
}
