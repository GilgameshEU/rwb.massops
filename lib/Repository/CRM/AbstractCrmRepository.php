<?php

namespace Rwb\Massops\Repository\CRM;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use CCrmFieldInfoAttr;
use CCrmFieldMulti;

abstract class AbstractCrmRepository
{
    abstract public function getType(): int;

    abstract public function getName(): string;

    protected function initContext(): void
    {
        Loader::requireModule('crm');
    }

    public function getFactory(): Factory
    {
        $this->initContext();

        $factory = Container::getInstance()->getFactory($this->getType());
        if (!$factory) {
            throw new \RuntimeException(
                'Factory not found for CRM type ' . $this->getType()
            );
        }

        return $factory;
    }

    public function getFieldList(): array
    {
        $result = [];

        $fields = $this->getFactory()->getFieldsCollection();

        //        foreach ($fields as $field) {
        //            echo '<pre>';
        //            echo $field->getName() . PHP_EOL;
        //            echo $field->getTitle() . PHP_EOL;
        //            echo $field->getType() . PHP_EOL;
        //            print_r($field->getAttributes());
        //            echo '</pre>';
        //        }

        foreach ($fields as $field) {
            if ($field->getName() === 'FM') {
                $result['PHONE'] = 'Телефон';
                $result['EMAIL'] = 'E-mail';
                continue;
            }

            if ($this->isFieldExcluded($field)) {
                continue;
            }

            $result[$field->getName()] = $field->getTitle();
        }

        //        var_dump($result);

        return $result;
    }

    public function isFieldExcluded($field): bool
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

    public function add(array $fields, array $fm = []): Result
    {
        $factory = $this->getFactory();
        $item = $factory->createItem($fields);

        $operation = $factory->getAddOperation($item);
        $operation->disableCheckAccess();

        $result = $operation->launch();
        if (!$result->isSuccess()) {
            return $result;
        }

        if (!empty($fm)) {
            $this->saveMultifields(
                $this->getName(),
                $item->getId(),
                $fm
            );
        }

        return $result;
    }

    protected function saveMultifields(string $entityName, int $entityId, array $fm): void
    {
        $fmData = [];

        foreach (['PHONE', 'EMAIL'] as $type) {
            if (empty($fm[$type]) || !is_array($fm[$type])) {
                continue;
            }

            $fmData[$type][] = [
                'VALUE' => $fm[$type],
                'VALUE_TYPE' => 'WORK',
            ];
        }

        if ($fmData) {
            $multi = new CCrmFieldMulti();
            $multi->setFields($entityName, $entityId, $fmData);
        }
    }
}
