<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Rwb\Massops\EntityRegistry;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\ImportErrorCode;
use Rwb\Massops\Support\UserFieldHelper;
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
            $this->log("job={$jobId} Decoding IMPORT_DATA, size={$dataSize} bytes");

            $allRows = json_decode($job['IMPORT_DATA'], true);

            if (!is_array($allRows)) {
                throw new RuntimeException(
                    'Не удалось декодировать данные импорта (IMPORT_DATA). '
                    . 'Размер поля: ' . $dataSize . ' байт'
                );
            }

            $entityType = $job['ENTITY_TYPE'];
            $startIndex = (int) $job['PROCESSED_ROWS'];
            $totalRows = (int) $job['TOTAL_ROWS'];
            $batchSize = $this->getBatchSize();

            // Декодируем все JSON-поля один раз — исключаем повторную десериализацию
            $options = !empty($job['IMPORT_OPTIONS'])
                ? (json_decode($job['IMPORT_OPTIONS'], true) ?? [])
                : [];

            $existingErrors = !empty($job['ERRORS_DATA'])
                ? (json_decode($job['ERRORS_DATA'], true) ?? [])
                : [];

            $existingIds = !empty($job['CREATED_IDS'])
                ? (json_decode($job['CREATED_IDS'], true) ?? [])
                : [];

            $endIndex = min($startIndex + $batchSize, $totalRows);

            $this->log("job={$jobId} Decoded OK, entityType={$entityType}, totalRows={$totalRows}, batch={$startIndex}..{$endIndex}");

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

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $rowIndex => $rowErrors) {
                    $existingErrors[$rowIndex] = array_map(
                        static fn($e) => $e instanceof ImportError ? $e->toArray() : $e,
                        $rowErrors
                    );
                }
            }

            if (!empty($result['added'])) {
                foreach ($result['added'] as $rowIndex => $item) {
                    if (!empty($item['entityId'])) {
                        $existingIds[$rowIndex] = [
                            'id'  => (int) $item['entityId'],
                            'cid' => $item['cid'] ?? null,
                        ];
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
                'ERRORS_DATA' => json_encode($existingErrors),
                'CREATED_IDS' => json_encode($existingIds),
            ];

            $isComplete = ($newProcessed >= $totalRows);

            if ($isComplete) {
                $updateData['STATUS'] = ImportJobStatus::Completed->value;
                $updateData['FINISHED_AT'] = new DateTime();
            }

            $this->log("job={$jobId} Updating progress: processed={$newProcessed}/{$totalRows}, success={$newSuccess}, errors={$newErrorCount}, complete=" . ($isComplete ? 'yes' : 'no'));

            ImportJobTable::update($jobId, $updateData);

            // Очищаем статический кэш UserFieldHelper после каждой пачки.
            // Агент — долгоживущий процесс; без очистки кэш разрастается и может
            // вернуть устаревшие данные, если UF-поля изменились между запусками.
            UserFieldHelper::clearCache();

            $this->log("job={$jobId} Batch done");

            return !$isComplete;
        } catch (\Throwable $e) {
            $this->log("job={$jobId} EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());

            // $existingErrors уже декодирован выше; если исключение произошло до
            // декодирования — используем пустой массив как безопасный фолбэк.
            $errorsData = $existingErrors ?? [];

            $errorsData['__exception__'] = [
                (new ImportError(
                    type: 'system',
                    code: ImportErrorCode::ProcessingException->value,
                    message: $e->getMessage(),
                ))->toArray(),
            ];

            try {
                $retryCount = (int) ($job['RETRY_COUNT'] ?? 0);
                $maxRetries = (int) ($job['MAX_RETRIES'] ?? 3);

                if ($retryCount < $maxRetries) {
                    // Задача ещё не исчерпала попытки — сбрасываем в Pending для повторной обработки
                    ImportJobTable::update($jobId, [
                        'STATUS' => ImportJobStatus::Pending->value,
                        'RETRY_COUNT' => $retryCount + 1,
                        'ERRORS_DATA' => json_encode($errorsData),
                    ]);
                    $this->log("job={$jobId} Retry {$retryCount}/{$maxRetries}, rescheduled to Pending");
                } else {
                    // Попытки исчерпаны — помечаем как ошибку
                    ImportJobTable::update($jobId, [
                        'STATUS' => ImportJobStatus::Error->value,
                        'FINISHED_AT' => new DateTime(),
                        'ERRORS_DATA' => json_encode($errorsData),
                    ]);
                    $this->log("job={$jobId} All retries exhausted ({$maxRetries}), marked as Error");
                }
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
