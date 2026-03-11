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

            $job = ImportJobTable::getList([
                'filter' => [
                    'STATUS' => [
                        ImportJobStatus::Pending->value,
                        ImportJobStatus::Processing->value,
                    ],
                ],
                'order' => ['ID' => 'ASC'],
                'limit' => 1,
                'select' => ['ID', 'STATUS'],
            ])->fetch();

            if (!$job) {
                return self::getAgentName();
            }

            // Если задача ещё в Pending — атомарно переводим её в Processing.
            // UPDATE WHERE STATUS='pending' выполняется атомарно на уровне MySQL:
            // если два агента стартуют одновременно, только один из них получит
            // affectedRows > 0 и продолжит работу.
            if ($job['STATUS'] === ImportJobStatus::Pending->value) {
                if (!self::tryClaimJob((int) $job['ID'])) {
                    // Другой агент уже захватил эту задачу — пропускаем тик
                    return self::getAgentName();
                }
            }

            self::log('START processBatch job=' . $job['ID']);

            $processor = new ImportProcessor();
            $processor->processBatch((int) $job['ID']);

            self::log('OK processBatch job=' . $job['ID']);
        } catch (\Throwable $e) {
            try {
                self::log(
                    'ERROR processBatch job=' . ($job['ID'] ?? '?')
                    . ': ' . $e->getMessage()
                    . "\n" . $e->getTraceAsString()
                );
            } catch (\Throwable) {
            }

            try {
                Application::getInstance()
                    ->getExceptionHandler()
                    ->writeToLog($e);
            } catch (\Throwable) {
            }
        }

        return self::getAgentName();
    }

    /**
     * Атомарно переводит задачу из Pending в Processing
     *
     * Использует UPDATE WHERE STATUS='pending' — MySQL выполняет это атомарно
     * на уровне row-lock. Если другой агент уже захватил задачу, affectedRows = 0.
     *
     * @return bool true если захват успешен, false если задачу уже взял другой агент
     */
    private static function tryClaimJob(int $jobId): bool
    {
        $connection = Application::getConnection();
        $helper    = $connection->getSqlHelper();
        $tableName = $helper->quote(ImportJobTable::getTableName());

        $connection->queryExecute(sprintf(
            "UPDATE %s SET %s = '%s', %s = NOW() WHERE %s = %d AND %s = '%s'",
            $tableName,
            $helper->quote('STATUS'), ImportJobStatus::Processing->value,
            $helper->quote('STARTED_AT'),
            $helper->quote('ID'), $jobId,
            $helper->quote('STATUS'), ImportJobStatus::Pending->value
        ));

        return $connection->getAffectedRowsCount() > 0;
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
    public static function log(string $message): void
    {
        $logLine = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";

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
            // mkdir с recursive=true атомарно создаёт всю цепочку директорий.
            // @ подавляет EEXIST при параллельном создании двумя агентами одновременно.
            // После вызова проверяем is_dir — это единственный надёжный способ убедиться,
            // что директория существует вне зависимости от исхода mkdir.
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);

                if (!is_dir($logDir)) {
                    return false;
                }
            }

            $logFile = $logDir . '/import_' . date('Y-m-d') . '.log';

            return file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
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
