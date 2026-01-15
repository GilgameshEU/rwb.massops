<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Context;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;

class RwbMassopsMainComponent extends CBitrixComponent implements Controllerable
{
    protected ErrorCollection $errors;

    public function __construct($component = null)
    {
        parent::__construct($component);
        $this->errors = new ErrorCollection();
    }

    /**
     * Обязательный метод для Controllerable
     */
    public function configureActions(): array
    {
        return [
            'test' => [
                'prefilters' => [], // CSRF уже включён по умолчанию
            ],
            'massAction' => [
                'prefilters' => [],
            ],
        ];
    }

    /**
     * Обычный вывод компонента (не AJAX)
     */
    public function executeComponent()
    {
        if (!$this->checkAccess()) {
            ShowError('Доступ запрещён');

            return;
        }

        $this->arResult = [
            'USER_ID' => CurrentUser::get()->getId(),
        ];

        $this->includeComponentTemplate();
    }

    /**
     * 🔥 AJAX action
     */
    public function testAction(): array
    {
        if (!$this->checkAccess()) {
            $this->addError('ACCESS_DENIED', 'Нет прав доступа');

            return [];
        }

        return [
            'status' => 'ok',
            'time' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Пример массового действия
     */
    public function massAction(array $ids, string $entity): array
    {
        if (!$this->checkAccess()) {
            $this->addError('ACCESS_DENIED', 'Нет прав доступа');

            return [];
        }

        if (empty($ids)) {
            $this->addError('EMPTY_IDS', 'Не переданы ID');

            return [];
        }

        // здесь логика массовых операций
        return [
            'entity' => $entity,
            'count' => count($ids),
        ];
    }

    protected function checkAccess(): bool
    {
        return CurrentUser::get()->isAdmin();
    }

    protected function addError(string $code, string $message): void
    {
        $this->errors->setError(new Error($message, $code));
    }

    public function getErrors(): array
    {
        return $this->errors->toArray();
    }
}
