<?php

namespace Rwb\Massops\Repository\CRM;

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
 * Базовый репозиторий CRM сущности
 */
abstract class ARepository
{
    /**
     * Исключенные поля
     */
    protected const EXCLUDED_FIELDS = [
        'OPENED',
    ];

    /**
     * Возвращает тип CRM сущности
     *
     * @return int
     */
    abstract public function getType(): int;

    /**
     * Возвращает имя сущности
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Инициализирует контекст CRM
     *
     * @throws LoaderException
     */
    protected function initContext(): void
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
     * Данные кешируются в рамках запроса.
     * Используется для формирования шаблонов импорта и определения обязательных полей.
     *
     * @return array<string, array{
     *     code: string,
     *     title: string,
     *     required: bool
     * }>
     *
     * @throws LoaderException
     */
    protected function getFieldsMeta(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        $fields = $this->getFactory()->getFieldsCollection();

        foreach ($fields as $field) {
            if ($this->isFieldExcluded($field)) {
                continue;
            }

            if ($field->getName() === Item::FIELD_NAME_FM) {
                $cache['PHONE'] = [
                    'code' => 'PHONE',
                    'title' => 'Телефон',
                    'required' => false,
                ];
                $cache['EMAIL'] = [
                    'code' => 'EMAIL',
                    'title' => 'E-mail',
                    'required' => false,
                ];
                continue;
            }

            $cache[$field->getName()] = [
                'code' => $field->getName(),
                'title' => $field->getTitle(),
                'required' => $field->isRequired(),
            ];
        }

        return $cache;
    }

    /**
     * Возвращает список доступных полей сущности
     *
     * Используется для:
     * - генерации шаблонов импорта
     * - маппинга полей CSV/XLSX
     *
     * @return array<string, string> код => название
     *
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
     *
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
     * Проверяет, должно ли поле быть исключено
     *
     * @param object $field
     *
     * @return bool
     */
    protected function isFieldExcluded(object $field): bool
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
}
