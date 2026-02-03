<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Exception;

/**
 * Bitrix-агент для обработки очереди импорта
 *
 * Запускается каждые 30 секунд, обрабатывает одну пачку строк
 * из самой старой активной задачи.
 */
class ImportAgent
{
    /**
     * Основной метод агента
     *
     * @return string Имя агента для перерегистрации
     */
    public static function process(): string
    {
        try {
            Loader::requireModule('rwb.massops');

            // Найти самую старую активную задачу
            $job = ImportJobTable::getList([
                'filter' => [
                    'STATUS' => [
                        ImportJobStatus::Pending->value,
                        ImportJobStatus::Processing->value,
                    ],
                ],
                'order' => ['ID' => 'ASC'],
                'limit' => 1,
                'select' => ['ID'],
            ])->fetch();

            if (!$job) {
                // Нет задач — ждём следующего тика
                return self::getAgentName();
            }

            $processor = new ImportProcessor();
            $processor->processBatch((int) $job['ID']);
        } catch (Exception $e) {
            // Логируем ошибку в event log Битрикс
            Application::getInstance()
                ->getExceptionHandler()
                ->writeToLog($e);
        }

        return self::getAgentName();
    }

    /**
     * Возвращает имя агента для регистрации
     */
    public static function getAgentName(): string
    {
        return '\\' . self::class . '::process();';
    }
}
