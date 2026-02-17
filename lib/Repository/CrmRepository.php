<?php

namespace Rwb\Massops\Repository;

use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Assembler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use CCrmFieldInfoAttr;
use RuntimeException;
use Bitrix\Crm\Field;

/**
 * Репозиторий CRM-сущности
 *
 * Предоставляет доступ к полям, метаданным и операциям добавления
 * для любого типа CRM-сущности (компании, контакты, сделки).
 */
final class CrmRepository
{
    /**
     * Исключенные поля
     */
    private const EXCLUDED_FIELDS = [
        'OPENED',
    ];

    /**
     * Кэш метаданных полей (per-instance)
     */
    private ?array $fieldMetaCache = null;

    /**
     * Кэш карты типов полей (per-instance)
     */
    private ?array $fieldTypeMapCache = null;

    /**
     * Кэш кодов множественных полей (per-instance)
     */
    private ?array $multipleFieldCodesCache = null;

    /**
     * Кэш маппинга enum: текст → ID
     */
    private ?array $enumMappingsCache = null;

    public function __construct(
        private readonly EntityType $entityType
    ) {
    }

    /**
     * Возвращает тип CRM сущности
     */
    public function getType(): int
    {
        return $this->entityType->crmTypeId();
    }

    /**
     * Возвращает имя сущности
     */
    public function getName(): string
    {
        return $this->entityType->crmName();
    }

    /**
     * Возвращает EntityType enum
     */
    public function getEntityType(): EntityType
    {
        return $this->entityType;
    }

    /**
     * Инициализирует контекст CRM
     *
     * @throws LoaderException
     */
    private function initContext(): void
    {
        Loader::requireModule('crm');
    }

    /**
     * Возвращает CRM Factory
     *
     * @throws LoaderException
     */
    public function getFactory(): Factory
    {
        $this->initContext();

        $factory = Container::getInstance()->getFactory($this->getType());
        if (!$factory) {
            throw new RuntimeException(
                'CRM Factory not found for type ' . $this->getType()
            );
        }

        return $factory;
    }

    /**
     * Возвращает мета-информацию о полях CRM-сущности
     *
     * Данные кешируются в рамках жизни объекта.
     *
     * @return array<string, array{code: string, title: string, required: bool}>
     * @throws LoaderException
     */
    private function getFieldsMeta(): array
    {
        if ($this->fieldMetaCache !== null) {
            return $this->fieldMetaCache;
        }

        $this->fieldMetaCache = [];
        $fields = $this->getFactory()->getFieldsCollection();

        foreach ($fields as $field) {
            if ($this->isFieldExcluded($field)) {
                continue;
            }

            if ($field->getName() === Item::FIELD_NAME_FM) {
                $this->fieldMetaCache['PHONE'] = [
                    'code' => 'PHONE',
                    'title' => 'Телефон',
                    'required' => false,
                ];
                $this->fieldMetaCache['EMAIL'] = [
                    'code' => 'EMAIL',
                    'title' => 'E-mail',
                    'required' => false,
                ];
                continue;
            }

            $this->fieldMetaCache[$field->getName()] = [
                'code' => $field->getName(),
                'title' => $field->getTitle(),
                'required' => $field->isRequired(),
            ];
        }

        return $this->fieldMetaCache;
    }

    /**
     * Возвращает список доступных полей сущности
     *
     * @return array<string, string> код => название
     * @throws LoaderException
     */
    public function getFieldList(): array
    {
        $result = [];

        foreach ($this->getFieldsMeta() as $field) {
            $result[$field['code']] = $field['title'];
        }

        return $result;
    }

    /**
     * Возвращает список кодов обязательных полей сущности
     *
     * @return string[]
     * @throws LoaderException
     */
    public function getRequiredFieldCodes(): array
    {
        $required = [];

        foreach ($this->getFieldsMeta() as $field) {
            if ($field['required']) {
                $required[] = $field['code'];
            }
        }

        return $required;
    }

    /**
     * Возвращает карту типов полей: fieldCode => typeId
     *
     * Для стандартных полей CRM возвращает тип из Field::getType().
     * Для UF-полей — USER_TYPE_ID (date, datetime, string, integer и т.д.).
     *
     * @return array<string, string>
     * @throws LoaderException
     */
    public function getFieldTypeMap(): array
    {
        if ($this->fieldTypeMapCache !== null) {
            return $this->fieldTypeMapCache;
        }

        $this->fieldTypeMapCache = [];
        $fields = $this->getFactory()->getFieldsCollection();
        $ufFieldsInfo = $this->getUfFieldsInfo();

        foreach ($fields as $field) {
            if ($this->isFieldExcluded($field)) {
                continue;
            }

            $fieldName = $field->getName();

            if ($fieldName === Item::FIELD_NAME_FM) {
                $this->fieldTypeMapCache['PHONE'] = 'string';
                $this->fieldTypeMapCache['EMAIL'] = 'string';
                continue;
            }

            // Для UF-полей берём USER_TYPE_ID
            if (str_starts_with($fieldName, 'UF_') && isset($ufFieldsInfo[$fieldName])) {
                $this->fieldTypeMapCache[$fieldName] = $ufFieldsInfo[$fieldName]['USER_TYPE_ID'] ?? 'string';
                continue;
            }

            // Для стандартных полей маппим тип
            $this->fieldTypeMapCache[$fieldName] = $field->getType();
        }

        return $this->fieldTypeMapCache;
    }

    /**
     * Возвращает коды UF-полей с MULTIPLE=Y
     *
     * @return string[]
     * @throws LoaderException
     */
    public function getMultipleFieldCodes(): array
    {
        if ($this->multipleFieldCodesCache !== null) {
            return $this->multipleFieldCodesCache;
        }

        $this->multipleFieldCodesCache = [];
        $ufFieldsInfo = $this->getUfFieldsInfo();

        foreach ($ufFieldsInfo as $fieldName => $fieldData) {
            if (($fieldData['MULTIPLE'] ?? 'N') === 'Y') {
                $this->multipleFieldCodesCache[] = $fieldName;
            }
        }

        return $this->multipleFieldCodesCache;
    }

    /**
     * Проверяет, должно ли поле быть исключено
     */
    private function isFieldExcluded(object $field): bool
    {
        $attributes = $field->getAttributes();

        if (
            in_array(CCrmFieldInfoAttr::ReadOnly, $attributes, true) ||
            in_array(CCrmFieldInfoAttr::NotDisplayed, $attributes, true) ||
            in_array(CCrmFieldInfoAttr::Deprecated, $attributes, true) ||
            in_array(CCrmFieldInfoAttr::Immutable, $attributes, true)
        ) {
            return true;
        }

        if ($field->getType() === Field::TYPE_FILE) {
            return true;
        }

        if (in_array($field->getName(), self::EXCLUDED_FIELDS, true)) {
            return true;
        }

        return false;
    }

    /**
     * Добавляет элемент CRM
     *
     * @param array $fields Основные поля
     * @param array $uf     Пользовательские поля
     * @param array $fm     Мультиполя
     * @param bool $dryRun  Выполнить валидацию без сохранения
     *
     * @return Result
     * @throws ArgumentException|LoaderException|InvalidOperationException
     */
    public function add(
        array $fields,
        array $uf = [],
        array $fm = [],
        bool $dryRun = false
    ): Result {
        $factory = $this->getFactory();

        $item = $factory->createItem($fields);

        foreach ($uf as $field => $value) {
            $item->set($field, $value);
        }

        if (!empty($fm)) {
            $fmCollection = $item->get(Item::FIELD_NAME_FM);
            Assembler::updateCollectionByArray($fmCollection, $fm);
            $item->set(Item::FIELD_NAME_FM, $fmCollection);
        }

        $operation = $factory->getAddOperation($item);
        $operation->disableCheckAccess();

        if ($dryRun === true) {
            return $operation->checkFields();
        }

        return $operation->launch();
    }

    /**
     * Возвращает информацию о полях сущности
     *
     * @return array
     * @throws LoaderException
     */
    public function getFieldsInfo(): array
    {
        return $this->getFactory()->getFieldsInfo();
    }

    /**
     * Возвращает полную информацию о полях для шаблона импорта
     *
     * @return array<string, array{
     *     code: string,
     *     title: string,
     *     required: bool,
     *     type: string,
     *     enumValues: array|null
     * }>
     * @throws LoaderException
     */
    public function getFieldsForTemplate(): array
    {
        $result = [];
        $fields = $this->getFactory()->getFieldsCollection();
        $fieldsInfo = $this->getFieldsInfo();
        $ufFieldsInfo = $this->getUfFieldsInfo();

        foreach ($fields as $field) {
            if ($this->isFieldExcluded($field)) {
                continue;
            }

            $fieldName = $field->getName();

            // Мультиполя (телефон, email)
            if ($fieldName === Item::FIELD_NAME_FM) {
                $result['PHONE'] = [
                    'code' => 'PHONE',
                    'title' => 'Телефон',
                    'required' => false,
                    'type' => 'Строка',
                    'enumValues' => null,
                ];
                $result['EMAIL'] = [
                    'code' => 'EMAIL',
                    'title' => 'E-mail',
                    'required' => false,
                    'type' => 'Строка',
                    'enumValues' => null,
                ];
                continue;
            }

            // Для UF полей берём информацию из CUserTypeEntity
            $extendedFieldInfo = $fieldsInfo[$fieldName] ?? [];
            if (str_starts_with($fieldName, 'UF_') && isset($ufFieldsInfo[$fieldName])) {
                $extendedFieldInfo = array_merge($extendedFieldInfo, $ufFieldsInfo[$fieldName]);
            }

            $fieldType = $this->resolveFieldType($field, $extendedFieldInfo);
            $enumValues = $this->getEnumValues($field, $extendedFieldInfo);

            $result[$fieldName] = [
                'code' => $fieldName,
                'title' => $field->getTitle(),
                'required' => $field->isRequired(),
                'type' => $fieldType,
                'enumValues' => $enumValues,
            ];
        }

        return $result;
    }

    /**
     * Получает информацию о пользовательских полях из CUserTypeEntity
     *
     * @return array<string, array>
     */
    private function getUfFieldsInfo(): array
    {
        $entityId = 'CRM_' . strtoupper($this->entityType->value);
        $result = [];

        $rsFields = \CUserTypeEntity::GetList(
            [],
            ['ENTITY_ID' => $entityId]
        );

        while ($field = $rsFields->Fetch()) {
            $result[$field['FIELD_NAME']] = $field;
        }

        return $result;
    }

    /**
     * Определяет человекочитаемый тип поля
     */
    private function resolveFieldType(object $field, array $fieldInfo): string
    {
        $type = $field->getType();

        $typeMap = [
            Field::TYPE_STRING => 'Строка',
            Field::TYPE_TEXT => 'Текст',
            Field::TYPE_CHAR => 'Символ',
            Field::TYPE_INTEGER => 'Целое число',
            Field::TYPE_DOUBLE => 'Число',
            Field::TYPE_BOOLEAN => 'Да/Нет',
            Field::TYPE_DATE => 'Дата',
            Field::TYPE_DATETIME => 'Дата и время',
            Field::TYPE_USER => 'Пользователь',
            Field::TYPE_LOCATION => 'Местоположение',
            Field::TYPE_CRM_STATUS => 'Справочник',
            Field::TYPE_CRM_CURRENCY => 'Валюта',
            Field::TYPE_CRM_COMPANY => 'Компания',
            Field::TYPE_CRM_CONTACT => 'Контакт',
            Field::TYPE_CRM_DEAL => 'Сделка',
            Field::TYPE_CRM_LEAD => 'Лид',
            Field::TYPE_CRM_QUOTE => 'Предложение',
            Field::TYPE_CRM_CATEGORY => 'Направление',
            Field::TYPE_CRM_PRODUCT_ROW => 'Товар',
            Field::TYPE_CRM_ENTITY => 'Сущность CRM',
            Field::TYPE_CRM_MULTIFIELD => 'Мультиполе',
            Field::TYPE_CRM_DYNAMIC_TYPE => 'Смарт-процесс',
        ];

        if (isset($typeMap[$type])) {
            return $typeMap[$type];
        }

        // Для UF полей
        if (str_starts_with($field->getName(), 'UF_')) {
            $userType = $fieldInfo['USER_TYPE_ID'] ?? $fieldInfo['TYPE'] ?? null;

            $ufTypeMap = [
                'string' => 'Строка',
                'integer' => 'Целое число',
                'double' => 'Число',
                'boolean' => 'Да/Нет',
                'date' => 'Дата',
                'datetime' => 'Дата и время',
                'enumeration' => 'Список',
                'employee' => 'Сотрудник',
                'crm' => 'Привязка к CRM',
                'crm_status' => 'Справочник CRM',
                'money' => 'Деньги',
                'url' => 'Ссылка',
                'address' => 'Адрес',
                'iblock_element' => 'Элемент инфоблока',
                'iblock_section' => 'Раздел инфоблока',
            ];

            if (isset($ufTypeMap[$userType])) {
                return $ufTypeMap[$userType];
            }
        }

        return 'Строка';
    }

    /**
     * Возвращает маппинг текстовых значений → ID/XML_ID для всех enum-полей
     *
     * Используется при импорте: пользователь вводит текст «Производство»,
     * а Bitrix ожидает STATUS_ID или XML_ID.
     *
     * @return array<string, array<string, string>> ['FIELD_CODE' => ['Текст' => 'id', ...], ...]
     * @throws LoaderException
     */
    public function getEnumMappings(): array
    {
        if ($this->enumMappingsCache !== null) {
            return $this->enumMappingsCache;
        }

        $this->enumMappingsCache = [];
        $templateFields = $this->getFieldsForTemplate();

        foreach ($templateFields as $code => $fieldData) {
            if (empty($fieldData['enumValues'])) {
                continue;
            }

            $mapping = [];
            foreach ($fieldData['enumValues'] as $item) {
                $mapping[$item['value']] = (string) $item['id'];
            }

            if (!empty($mapping)) {
                $this->enumMappingsCache[$code] = $mapping;
            }
        }

        return $this->enumMappingsCache;
    }

    /**
     * Получает значения enum для списочных полей
     */
    private function getEnumValues(object $field, array $fieldInfo): ?array
    {
        $type = $field->getType();
        $fieldName = $field->getName();

        // CRM статусы (SOURCE_ID, INDUSTRY и т.п.)
        if ($type === Field::TYPE_CRM_STATUS) {
            $statusEntityId = $fieldInfo['CRM_STATUS_TYPE'] ?? null;
            if ($statusEntityId) {
                return $this->getCrmStatusItems($statusEntityId);
            }
        }

        // UF поля типа enumeration
        if (str_starts_with($fieldName, 'UF_')) {
            $userType = $fieldInfo['USER_TYPE_ID'] ?? $fieldInfo['TYPE'] ?? null;

            if ($userType === 'enumeration') {
                return $this->getUfEnumItems($fieldName);
            }
        }

        return null;
    }

    /**
     * Получает элементы справочника CRM
     */
    private function getCrmStatusItems(string $entityId): array
    {
        $items = [];

        $statuses = \CCrmStatus::GetStatusList($entityId);
        foreach ($statuses as $statusId => $statusName) {
            $items[] = [
                'id' => $statusId,
                'value' => $statusName,
            ];
        }

        return $items;
    }

    /**
     * Получает значения enum для UF поля
     */
    private function getUfEnumItems(string $fieldName): array
    {
        $items = [];

        $entityId = 'CRM_' . strtoupper($this->entityType->value);

        $rsField = \CUserTypeEntity::GetList(
            [],
            ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName]
        );

        if ($field = $rsField->Fetch()) {
            $rsEnum = \CUserFieldEnum::GetList(
                ['SORT' => 'ASC'],
                ['USER_FIELD_ID' => $field['ID']]
            );

            while ($enum = $rsEnum->Fetch()) {
                $items[] = [
                    'id' => (string) $enum['ID'],
                    'value' => $enum['VALUE'],
                ];
            }
        }

        return $items;
    }
}
