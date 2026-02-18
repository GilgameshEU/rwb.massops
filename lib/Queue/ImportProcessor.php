<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Rwb\Massops\EntityRegistry;
use Rwb\Massops\Import\ImportError;
use RuntimeException;

/**
 * Обработчик задач импорта
 *
 * Обрабатывает строки пачками (BATCH_SIZE), обновляя прогресс в БД.
 */
class ImportProcessor
{
    /**
     * Количество строк в одной пачке по умолчанию
     */
    private const DEFAULT_BATCH_SIZE = 50;

    /**
     * Возвращает размер пачки из настроек модуля
     */
    private function getBatchSize(): int
    {
        $size = (int) Option::get('rwb.massops', 'queue_batch_size', self::DEFAULT_BATCH_SIZE);

        return $size > 0 ? $size : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Обрабатывает очередную пачку строк задачи
     *
     * @param int $jobId ID задачи
     *
     * @return bool true если есть ещё строки для обработки
     *
     * @throws RuntimeException
     */
    public function processBatch(int $jobId): bool
    {
        Loader::requireModule('rwb.massops');

        $job = ImportJobTable::getById($jobId)->fetch();

        if (!$job) {
            throw new RuntimeException("Job $jobId not found");
        }

        $status = $job['STATUS'];

        if (in_array($status, [
            ImportJobStatus::Completed->value,
            ImportJobStatus::Error->value,
        ], true)) {
            return false;
        }

        if ($status === ImportJobStatus::Pending->value) {
            ImportJobTable::update($jobId, [
                'STATUS' => ImportJobStatus::Processing->value,
                'STARTED_AT' => new DateTime(),
            ]);
        }

        try {
            $allRows = unserialize($job['IMPORT_DATA']);

            if (!is_array($allRows)) {
                throw new RuntimeException(
                    'Не удалось десериализовать данные импорта (IMPORT_DATA). '
                    . 'Вероятно, данные были обрезаны при записи в БД. '
                    . 'Размер поля: ' . strlen($job['IMPORT_DATA'] ?? '') . ' байт'
                );
            }

            $entityType = $job['ENTITY_TYPE'];
            $startIndex = (int) $job['PROCESSED_ROWS'];
            $totalRows = (int) $job['TOTAL_ROWS'];
            $batchSize = $this->getBatchSize();

            $options = [];
            if (!empty($job['IMPORT_OPTIONS'])) {
                $options = unserialize($job['IMPORT_OPTIONS']);
            }

            $endIndex = min($startIndex + $batchSize, $totalRows);

            $allRowsIndexed = array_values($allRows);
            $batchRows = [];
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $batchRows[$i] = $allRowsIndexed[$i];
            }

            $importService = EntityRegistry::createImportService($entityType);
            $result = $importService->import($batchRows, $options);

            $existingErrors = !empty($job['ERRORS_DATA'])
                ? unserialize($job['ERRORS_DATA'])
                : [];

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $rowIndex => $rowErrors) {
                    $existingErrors[$rowIndex] = $rowErrors;
                }
            }

            $existingIds = !empty($job['CREATED_IDS'])
                ? unserialize($job['CREATED_IDS'])
                : [];

            if (!empty($result['added'])) {
                foreach ($result['added'] as $item) {
                    if (!empty($item['entityId'])) {
                        $existingIds[] = (int) $item['entityId'];
                    }
                }
            }

            $newProcessed = $endIndex;
            $newSuccess = (int) $job['SUCCESS_COUNT'] + $result['success'];
            $newErrorCount = (int) $job['ERROR_COUNT'] + count($result['errors']);

            $updateData = [
                'PROCESSED_ROWS' => $newProcessed,
                'SUCCESS_COUNT' => $newSuccess,
                'ERROR_COUNT' => $newErrorCount,
                'ERRORS_DATA' => serialize($existingErrors),
                'CREATED_IDS' => serialize($existingIds),
            ];

            $isComplete = ($newProcessed >= $totalRows);

            if ($isComplete) {
                $updateData['STATUS'] = ImportJobStatus::Completed->value;
                $updateData['FINISHED_AT'] = new DateTime();
            }

            ImportJobTable::update($jobId, $updateData);

            return !$isComplete;
        } catch (\Throwable $e) {
            $errorsData = [];
            try {
                if (!empty($job['ERRORS_DATA'])) {
                    $errorsData = unserialize($job['ERRORS_DATA']) ?: [];
                }
            } catch (\Throwable) {
                $errorsData = [];
            }

            $errorsData['__exception__'] = [
                new ImportError(
                    type: 'system',
                    code: 'PROCESSING_EXCEPTION',
                    message: $e->getMessage(),
                ),
            ];

            ImportJobTable::update($jobId, [
                'STATUS' => ImportJobStatus::Error->value,
                'FINISHED_AT' => new DateTime(),
                'ERRORS_DATA' => serialize($errorsData),
            ]);

            throw $e;
        }
    }
}
