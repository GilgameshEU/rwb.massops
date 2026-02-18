<?php

namespace Rwb\Massops\Menu;

use Bitrix\Intranet\Controller\LeftMenu;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * Установщик пункта меню в левое меню Bitrix24 (публичная часть)
 *
 * Вызывается при первом посещении страницы /massops/ администратором.
 * Добавляет пункт меню для всех пользователей.
 */
class MenuInstaller
{
    private const MODULE_ID = 'rwb.massops';
    private const MENU_TITLE = 'Массовые операции';
    private const MENU_LINK = '/massops/';

    /**
     * Одноразовая установка меню при первом посещении страницы /massops/
     * Проверяет, не установлено ли уже меню, и добавляет его для всех пользователей
     */
    public static function installMenuOnce(): void
    {
        try {
            $existingItemId = Option::get(self::MODULE_ID, 'left_menu_item_id', '');
            if (!empty($existingItemId)) {
                return;
            }

            global $USER;
            if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
                return;
            }

            self::installMenu();
        } catch (\Throwable $e) {
        }
    }

    /**
     * Добавляет пункт меню в левое меню Bitrix24 для всех пользователей
     *
     * @throws LoaderException|ArgumentOutOfRangeException
     */
    private static function installMenu(): void
    {
        if (!Loader::includeModule('intranet')) {
            return;
        }

        if (!class_exists('\Bitrix\Intranet\Controller\LeftMenu')) {
            return;
        }

        $oldPost = $_POST ?? [];

        $_POST['itemData'] = [
            'text' => self::MENU_TITLE,
            'link' => self::MENU_LINK,
        ];

        $controller = new LeftMenu();
        $response = $controller->addSelfItemAction();

        if (!empty($response['itemId'])) {
            $itemId = $response['itemId'];

            $_POST['itemInfo'] = [
                'text' => self::MENU_TITLE,
                'link' => self::MENU_LINK,
                'id' => $itemId,
                'openInNewPage' => 'N',
            ];

            $controller->addItemToAllAction();
            Option::set(self::MODULE_ID, 'left_menu_item_id', $itemId);
        }

        $_POST = $oldPost;
    }

    /**
     * Удаляет пункт меню для всех пользователей
     * Вызывается при деинсталляции модуля
     */
    public static function uninstallMenu(): void
    {
        try {
            if (!Loader::includeModule('intranet')) {
                return;
            }

            $itemId = Option::get(self::MODULE_ID, 'left_menu_item_id', '');

            if (!empty($itemId) && class_exists('\Bitrix\Intranet\Controller\LeftMenu')) {
                $oldPost = $_POST ?? [];

                $controller = new LeftMenu();

                $_POST['menu_item_id'] = $itemId;
                $controller->deleteItemFromAllAction();

                $controller->deleteSelfItemAction($itemId);

                $_POST = $oldPost;
            }

            Option::delete(self::MODULE_ID, ['name' => 'left_menu_item_id']);
        } catch (\Throwable $e) {
        }
    }
}
