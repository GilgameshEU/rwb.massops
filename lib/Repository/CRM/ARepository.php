<?php

namespace Rwb\Massops\Repository\CRM;

use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Assembler;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use CCrmFieldInfoAttr;
use RuntimeException;

/**
 * Базовый репозиторий CRM сущности
 */
abstract class ARepository
{
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
     * Возвращает список доступных полей сущности
     *
     * Используется для шаблона и маппинга полей импорта
     *
     * @return array<string, string>
     * @throws LoaderException
     */
    public function getFieldList(): array
    {
        $result = [];
        $fields = $this->getFactory()->getFieldsCollection();

        foreach ($fields as $field) {
            if ($this->isFieldExcluded($field)) {
                continue;
            }

            if ($field->getName() === Item::FIELD_NAME_FM) {
                $result['PHONE'] = 'Телефон';
                $result['EMAIL'] = 'E-mail';
                continue;
            }

            $result[$field->getName()] = $field->getTitle();
        }

        return $result;
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

        if ($field->getType() === 'file') {
            return true;
        }

        return false;
    }

    /**
     * Добавляет элемент CRM
     *
     * @param array $fields           Основные поля
     * @param array $uf               Пользовательские поля
     * @param array $fm               Мультиполя
     * @param callable|null $settings Настройка операции
     *
     * @return Result
     * @throws ArgumentException|LoaderException
     */
    public function add(
        array $fields,
        array $uf = [],
        array $fm = [],
        ?callable $settings = null
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

        if ($settings) {
            $settings($operation);
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
