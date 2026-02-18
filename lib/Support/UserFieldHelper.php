<?php

namespace Rwb\Massops\Support;

use Bitrix\Main\UserFieldTable;

/**
 * Хелпер для работы с пользовательскими полями CRM
 */
class UserFieldHelper
{
    /**
     * Кэш полей по entity_id и xml_id
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * Возвращает код пользовательского поля по XML_ID
     *
     * @param string $entityId  ID сущности (например: CRM_COMPANY, CRM_CONTACT)
     * @param string $xmlId     XML_ID поля
     *
     * @return string|null Код поля (UF_CRM_XXX) или null если не найдено
     */
    public static function getFieldCodeByXmlId(string $entityId, string $xmlId): ?string
    {
        $cacheKey = $entityId . '|' . $xmlId;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $field = UserFieldTable::getList([
            'filter' => [
                '=ENTITY_ID' => $entityId,
                '=XML_ID' => $xmlId,
            ],
            'select' => ['FIELD_NAME'],
            'limit' => 1,
        ])->fetch();

        $fieldName = $field ? $field['FIELD_NAME'] : null;

        self::$cache[$cacheKey] = $fieldName;

        return $fieldName;
    }

    /**
     * Возвращает код поля комментариев для компании
     *
     * @return string|null
     */
    public static function getCompanyCommentsField(): ?string
    {
        return self::getFieldCodeByXmlId('CRM_COMPANY', 'COMMENTS');
    }

    /**
     * Возвращает код поля комментариев для контакта
     *
     * @return string|null
     */
    public static function getContactCommentsField(): ?string
    {
        return self::getFieldCodeByXmlId('CRM_CONTACT', 'COMMENTS');
    }

    /**
     * Сбрасывает кэш (полезно для тестов)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
