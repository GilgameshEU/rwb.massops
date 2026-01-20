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

abstract class ARepository
{
    abstract public function getType(): int;

    abstract public function getName(): string;

    /**
     * @throws LoaderException
     */
    protected function initContext(): void
    {
        Loader::requireModule('crm');
    }

    /**
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
     * Получение списка доступных полей (для шаблона / маппинга)
     *
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

    protected function isFieldExcluded($field): bool
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
     *
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
     * @throws LoaderException
     */
    public function getFieldsInfo(): array
    {
        return $this->getFactory()->getFieldsInfo();
    }
}
