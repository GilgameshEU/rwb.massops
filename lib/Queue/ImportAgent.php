<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

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
     * ВАЖНО: этот метод должен ВСЕГДА возвращать self::getAgentName(),
     * иначе Bitrix удалит агент из таблицы b_agent и очередь остановится.
     *
     * @return string Имя агента для перерегистрации
     */
    public static function process(): string
    {
        $job = null;

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
                return self::getAgentName();
            }

            self::log('START processBatch job=' . $job['ID']);

            $processor = new ImportProcessor();
            $processor->processBatch((int) $job['ID']);

            self::log('OK processBatch job=' . $job['ID']);
        } catch (\Throwable $e) {
            // Каждый вызов обёрнут отдельно, чтобы сбой в одном
            // не помешал выполнению другого и не убил агент
            try {
                self::log(
                    'ERROR processBatch job=' . ($job['ID'] ?? '?')
                    . ': ' . $e->getMessage()
                    . "\n" . $e->getTraceAsString()
                );
            } catch (\Throwable) {
                // log() не должен бросать, но на всякий случай
            }

            try {
                Application::getInstance()
                    ->getExceptionHandler()
                    ->writeToLog($e);
            } catch (\Throwable) {
                // Bitrix exception handler тоже может упасть
            }
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

    /**
     * Безопасная запись в лог-файл модуля
     *
     * Метод спроектирован так, чтобы НИКОГДА не бросать исключений.
     * При ошибке основного пути пробует fallback-пути.
     * Лог: /upload/rwb_massops_logs/import_YYYY-MM-DD.log
     */
    private static function log(string $message): void
    {
        $logLine = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";

        // Пробуем несколько вариантов document root
        $roots = array_filter([
            self::getDocumentRootSafe(),
            $_SERVER['DOCUMENT_ROOT'] ?? null,
        ]);

        foreach ($roots as $root) {
            $logDir = $root . '/upload/rwb_massops_logs';
            if (self::writeToFile($logDir, $logLine)) {
                return;
            }
        }

        // Последний fallback — системный temp
        $fallbackDir = sys_get_temp_dir() . '/rwb_massops_logs';
        self::writeToFile($fallbackDir, $logLine);
    }

    /**
     * Пробует записать строку в лог-файл в указанной директории
     *
     * @return bool true если запись удалась
     */
    private static function writeToFile(string $logDir, string $logLine): bool
    {
        try {
            if (!@is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }

            $logFile = $logDir . '/import_' . date('Y-m-d') . '.log';

            return @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Безопасно получает document root через Bitrix API
     */
    private static function getDocumentRootSafe(): ?string
    {
        try {
            return Application::getDocumentRoot();
        } catch (\Throwable) {
            return null;
        }
    }
}
