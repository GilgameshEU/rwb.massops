<?php

if (class_exists('rwb_massops')) {
    return;
}

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SystemException;

class rwb_massops extends CModule
{
    public $MODULE_ID = 'rwb.massops';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public string $MODULE_FOLDER;
    public $PARTNER_NAME;

    /**
     * Конструктор класса rwb_massops
     * Для модуля rwb.massops
     */
    public function __construct()
    {
        $moduleVersion = [];

        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $moduleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $moduleVersion['VERSION_DATE'];

        $this->MODULE_NAME = Loc::getMessage('RWB_MASSOPS_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('RWB_MASSOPS_MODULE_DESCRIPTION');
        $this->MODULE_FOLDER = dirname(__DIR__, 1);

        $this->PARTNER_NAME = Loc::getMessage('RWB_PARTNER_NAME');
    }

    private function hasError(): bool
    {
        global $APPLICATION;

        return !empty($APPLICATION->getException());
    }

    private function showError(): void
    {
        global $APPLICATION;

        $APPLICATION->includeAdminFile(
            Loc::getMessage('RWB_MASSOPS_MODULE_INSTALL_ERROR'),
            $this->MODULE_FOLDER . '/install/error.php'
        );
    }

    /**
     * Установка модуля rwb.massops
     *
     * @return bool
     */
    public function doInstall(): bool
    {
        $this->checkRules();
        if ($this->hasError()) {
            $this->showError();

            return false;
        }

        $this->installDB();
        $this->installEvents();
        $this->installFiles();

        return true;
    }

    /**
     * Удаление модуля rwb.massops
     *
     * Шаг 1 — показывает форму подтверждения (unstep.php)
     * Шаг 2 — выполняет удаление с учётом выбора пользователя
     *
     * @return bool
     * @throws ArgumentNullException
     */
    public function doUninstall(): bool
    {
        global $APPLICATION;

        $request = Application::getInstance()->getContext()->getRequest();
        $step = (int) $request->get('step');

        if ($step < 2) {
            $APPLICATION->includeAdminFile(
                Loc::getMessage('RWB_MASSOPS_UNINSTALL'),
                $this->MODULE_FOLDER . '/install/unstep.php'
            );

            return true;
        }

        $this->unInstallFiles();
        $this->unInstallEvents();
        $this->unInstallDB();

        return true;
    }

    /**
     * Метод работает с настройками модуля и таблицами
     *
     * @return bool
     */
    public function installDB(): bool
    {
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        $this->installOrm();
        $this->installAgent();

        return true;
    }

    /**
     * Удаление частей модуля по базе
     *
     * @return bool
     * @throws ArgumentNullException
     */
    public function unInstallDB(): bool
    {
        Loader::includeModule($this->MODULE_ID);
        $postList = Application::getInstance()->getContext()->getRequest()->toArray();

        if ($postList['save_option'] !== 'Y') {
            $this->unInstallOptions();
        }
        if ($postList['save_data'] !== 'Y') {
            $this->unInstallOrm();
        }
        $this->unInstallAgent();
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    /**
     * Установка ORM таблиц
     *
     * @return void
     */
    public function installOrm(): void
    {
        global $APPLICATION;

        try {
            foreach ($this->getOrmList() as $ormClass) {
                $this->createTable($ormClass);
            }
        } catch (Exception $exception) {
            $APPLICATION->throwException($exception->getMessage());
        }
    }

    /**
     * Удаление ORM таблиц
     *
     * @return void
     */
    public function unInstallOrm(): void
    {
        global $APPLICATION;

        try {
            foreach ($this->getOrmList() as $ormClass) {
                $this->dropTable($ormClass);
            }
        } catch (Exception $exception) {
            $APPLICATION->throwException($exception->getMessage());
        }
    }

    /**
     * Установка файлов
     *
     * @return void
     */
    public function installFiles(): void
    {
        CopyDirFiles(
            $this->MODULE_FOLDER . '/install/components',
            Application::getDocumentRoot() . '/local/components',
            true,
            true
        );

        CopyDirFiles(
            $this->MODULE_FOLDER . '/install/public',
            Application::getDocumentRoot(),
            true,
            true
        );
    }

    /**
     * Удаляем файлы
     *
     * @return void
     */
    public function unInstallFiles(): void
    {
        DeleteDirFilesEx('/local/components/rwb/massops.main');
        DeleteDirFilesEx('massops');
    }

    /**
     * Подвязываем события
     *
     * @return void
     */
    public function installEvents(): void
    {
        foreach ($this->getEventList() as $event) {
            EventManager::getInstance()->registerEventHandler(...$event);
        }
    }

    /**
     * Отвязываем события
     *
     * @return void
     */
    public function unInstallEvents(): void
    {
        foreach ($this->getEventList() as $event) {
            EventManager::getInstance()->unRegisterEventHandler(...$event);
        }
    }

    /**
     * Очищает настройки
     *
     * @return void
     * @throws ArgumentNullException
     * @throws ArgumentException
     */
    public function unInstallOptions(): void
    {
        Option::delete($this->MODULE_ID);
    }

    /**
     * Набор правил валидации установки модуля
     *
     * @return void
     */
    private function checkRules(): void
    {
        global $APPLICATION;

        $requiredVersion = '8.1';
        if (version_compare(phpversion(), $requiredVersion, '<')) {
            $APPLICATION->throwException(
                Loc::getMessage(
                    'RWB_MASSOPS_PHP_LOWER',
                    [
                        '#VER#' => $requiredVersion,
                    ]
                )
            );

            return;
        }

        if (!check_bitrix_sessid() || !CurrentUser::get()->isAdmin()) {
            $APPLICATION->throwException(
                Loc::getMessage('RWB_MASSOPS_ERROR_PERMISSION')
            );
        }
    }

    /**
     * Пакетная установка произвольных SQL запросов
     *
     * @return void
     */
    private function installSql(): void
    {
        global $APPLICATION, $DB;

        $error = $DB->runSQLBatch($this->MODULE_FOLDER . '/install/db/install.sql');
        if ($error !== false) {
            $APPLICATION->throwException(implode('<br>', $error));
        }
    }

    /**
     * Пакетное удаление произвольных SQL запросов
     *
     * @return void
     */
    private function unInstallSql(): void
    {
        global $APPLICATION, $DB;

        $errors = $DB->runSQLBatch($this->MODULE_FOLDER . '/install/db/uninstall.sql');
        if ($errors !== false) {
            $APPLICATION->throwException(implode('<br>', $errors));
        }
    }

    /**
     * Создаем свою Orm таблицу, передаем полное имя класса таблицы Orm::class
     *
     * @param $className
     *
     * @return void
     * @throws ArgumentException
     * @throws SystemException
     */
    private function createTable($className): void
    {
        $table = Base::getInstance($className);
        $connectionName = $className::getConnectionName();
        $connection = Application::getConnection($connectionName);
        $tableName = $table->getDBTableName();
        $tableExist = $connection->isTableExists($tableName);

        if (!$tableExist) {
            $table->createDbTable();
        }
    }

    /**
     * Удаляем свою Orm таблицу, передаем полное имя класса таблицы Orm::class
     *
     * @param $className
     *
     * @return void
     * @throws ArgumentException
     * @throws SqlQueryException
     * @throws SystemException
     */
    private function dropTable($className): void
    {
        $table = Base::getInstance($className);
        $connectionName = $className::getConnectionName();
        $tableName = $table->getDBTableName();
        Application::getConnection($connectionName)
            ->queryExecute('drop table if exists ' . $tableName);
    }

    /**
     * Список ORM регистрируется в системе
     *
     * @return array
     */
    public function getOrmList(): array
    {
        return [
            \Rwb\Massops\Queue\ImportJobTable::class,
        ];
    }

    /**
     * Регистрирует агент обработки очереди импорта
     */
    private function installAgent(): void
    {
        \CAgent::AddAgent(
            \Rwb\Massops\Queue\ImportAgent::getAgentName(),
            $this->MODULE_ID,
            'N',
            30,
            '',
            'Y',
            '',
            100
        );
    }

    /**
     * Удаляет агент обработки очереди импорта
     */
    private function unInstallAgent(): void
    {
        \CAgent::RemoveAgent(
            \Rwb\Massops\Queue\ImportAgent::getAgentName(),
            $this->MODULE_ID
        );
    }

    /**
     * События из метода регистрируются в системе
     * добавляем массив строк:
     *
     * Модуль
     * Событие
     * $this->MODULE_ID
     * Класс::class обработчика
     * МетодОбработчика
     *
     * @return array
     */
    private function getEventList(): array
    {
        return [
            // Пример: ['main', 'OnPageStart', $this->MODULE_ID, '\Rwb\Massops\Events', 'onPageStart'],
        ];
    }
}
