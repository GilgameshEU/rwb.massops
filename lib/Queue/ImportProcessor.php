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
            $dataSize = strlen($job['IMPORT_DATA'] ?? '');
            $this->log("job={$jobId} Deserializing IMPORT_DATA, size={$dataSize} bytes");

            $allRows = unserialize($job['IMPORT_DATA']);

            if (!is_array($allRows)) {
                throw new RuntimeException(
                    'Не удалось десериализовать данные импорта (IMPORT_DATA). '
                    . 'Вероятно, данные были обрезаны при записи в БД. '
                    . 'Размер поля: ' . $dataSize . ' байт'
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

            $this->log("job={$jobId} Deserialized OK, entityType={$entityType}, totalRows={$totalRows}, batch={$startIndex}..{$endIndex}");

            $allRowsIndexed = array_values($allRows);
            $batchRows = [];
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $batchRows[$i] = $allRowsIndexed[$i];
            }

            $batchCount = count($batchRows);
            $this->log("job={$jobId} Calling importService->import(), batchSize={$batchCount}");

            $importService = EntityRegistry::createImportService($entityType);
            $result = $importService->import($batchRows, $options);

            $this->log("job={$jobId} Import result: success={$result['success']}, errors=" . count($result['errors']));

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
                foreach ($result['added'] as $rowIndex => $item) {
                    if (!empty($item['entityId'])) {
                        $existingIds[$rowIndex] = (int) $item['entityId'];
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

            $this->log("job={$jobId} Updating progress: processed={$newProcessed}/{$totalRows}, success={$newSuccess}, errors={$newErrorCount}, complete=" . ($isComplete ? 'yes' : 'no'));

            ImportJobTable::update($jobId, $updateData);

            $this->log("job={$jobId} Batch done");

            return !$isComplete;
        } catch (\Throwable $e) {
            $this->log("job={$jobId} EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());

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

            try {
                ImportJobTable::update($jobId, [
                    'STATUS' => ImportJobStatus::Error->value,
                    'FINISHED_AT' => new DateTime(),
                    'ERRORS_DATA' => serialize($errorsData),
                ]);
                $this->log("job={$jobId} Error saved to DB, re-throwing");
            } catch (\Throwable $updateEx) {
                $this->log("job={$jobId} FAILED to save error to DB: " . $updateEx->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Делегирует запись в лог агенту
     */
    private function log(string $message): void
    {
        ImportAgent::log($message);
    }
}
