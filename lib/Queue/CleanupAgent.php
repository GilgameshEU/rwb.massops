<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Битрикс-агент для очистки устаревших задач импорта
 *
 * Запускается раз в сутки (86400 сек).
 * Удаляет завершённые/ошибочные задачи (и их строки) старше N дней.
 * N задаётся настройкой cleanup_days (по умолчанию 30).
 */
class CleanupAgent
{
    private const DEFAULT_CLEANUP_DAYS = 30;

    /**
     * Основной метод агента
     *
     * @return string Имя агента для перерегистрации
     */
    public static function process(): string
    {
        try {
            Loader::requireModule('rwb.massops');

            $days = (int) Option::get('rwb.massops', 'cleanup_days', self::DEFAULT_CLEANUP_DAYS);

            if ($days <= 0) {
                $days = self::DEFAULT_CLEANUP_DAYS;
            }

            // Порог по времени вычисляется через PHP — совместимо с любой СУБД
            $cutoffDate = DateTime::createFromTimestamp(time() - $days * 86400);

            // Находим устаревшие задачи через ORM (не зависит от СУБД)
            $result = ImportJobTable::getList([
                'filter' => [
                    'STATUS'      => [ImportJobStatus::Completed->value, ImportJobStatus::Error->value],
                    '<FINISHED_AT' => $cutoffDate,
                ],
                'select' => ['ID'],
            ]);

            $jobIds = [];
            while ($job = $result->fetch()) {
                $jobIds[] = (int) $job['ID'];
            }

            if (empty($jobIds)) {
                ImportAgent::log('CleanupAgent: nothing to clean up');
                return self::getAgentName();
            }

            $connection  = Application::getConnection();
            $helper      = $connection->getSqlHelper();
            $queueTable  = $helper->quote(ImportJobTable::getTableName());
            $rowsTable   = $helper->quote(ImportJobRowTable::getTableName());
            $qJobId      = $helper->quote('JOB_ID');
            $qId         = $helper->quote('ID');

            $deletedRows = 0;
            $deletedJobs = 0;

            // Удаляем пачками по 200, чтобы не создавать слишком длинный IN(...)
            foreach (array_chunk($jobIds, 200) as $chunk) {
                $idsStr = implode(',', $chunk);

                $connection->queryExecute(
                    "DELETE FROM {$rowsTable} WHERE {$qJobId} IN ({$idsStr})"
                );
                $deletedRows += $connection->getAffectedRowsCount();

                $connection->queryExecute(
                    "DELETE FROM {$queueTable} WHERE {$qId} IN ({$idsStr})"
                );
                $deletedJobs += $connection->getAffectedRowsCount();
            }

            ImportAgent::log(
                "CleanupAgent: removed {$deletedJobs} job(s) and {$deletedRows} row(s) older than {$days} day(s)"
            );
        } catch (\Throwable $e) {
            try {
                ImportAgent::log('CleanupAgent ERROR: ' . $e->getMessage());
                Application::getInstance()->getExceptionHandler()->writeToLog($e);
            } catch (\Throwable) {
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
}
