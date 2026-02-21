<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Rwb\Massops\EntityRegistry;
use Rwb\Massops\Queue\ImportJobStatus;
use Rwb\Massops\Queue\ImportJobTable;

/**
 * Сервис управления задачами импорта
 *
 * Инкапсулирует бизнес-логику работы с очередью:
 * - создание задач
 * - получение прогресса
 * - получение истории с пагинацией
 */
class ImportJobService
{
    /**
     * Создаёт задачу импорта в очереди
     *
     * @param int    $userId     ID пользователя-инициатора
     * @param string $entityType Тип сущности (company, contact и т.д.)
     * @param array  $rows       Данные строк для импорта
     * @param array  $options    Опции импорта (columns, createCabinets и т.д.)
     *
     * @return int ID созданной задачи
     * @throws \RuntimeException
     */
    public function createJob(int $userId, string $entityType, array $rows, array $options): int
    {
        $result = ImportJobTable::add([
            'USER_ID' => $userId,
            'ENTITY_TYPE' => $entityType,
            'STATUS' => ImportJobStatus::Pending->value,
            'TOTAL_ROWS' => count($rows),
            'ERRORS_DATA' => json_encode([]),
            'CREATED_IDS' => json_encode([]),
            'IMPORT_DATA' => json_encode($rows),
            'IMPORT_OPTIONS' => json_encode($options),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException('Не удалось создать задачу импорта');
        }

        return $result->getId();
    }

    /**
     * Получает прогресс задачи импорта
     *
     * @param int $jobId  ID задачи
     * @param int $userId ID пользователя (для проверки доступа)
     *
     * @return array Данные прогресса для фронтенда
     * @throws \RuntimeException|AccessDeniedException
     */
    public function getProgress(int $jobId, int $userId): array
    {
        if (!$jobId) {
            throw new \RuntimeException('Job ID не указан');
        }

        $job = ImportJobTable::getById($jobId)->fetch();

        if (!$job) {
            throw new \RuntimeException('Задача не найдена');
        }

        if ((int) $job['USER_ID'] !== $userId) {
            throw new AccessDeniedException();
        }

        $isComplete = in_array($job['STATUS'], [
            ImportJobStatus::Completed->value,
            ImportJobStatus::Error->value,
        ], true);

        $totalRows = (int) $job['TOTAL_ROWS'];

        $response = [
            'jobId' => $jobId,
            'status' => $job['STATUS'],
            'totalRows' => $totalRows,
            'processedRows' => (int) $job['PROCESSED_ROWS'],
            'successCount' => (int) $job['SUCCESS_COUNT'],
            'errorCount' => (int) $job['ERROR_COUNT'],
            'isComplete' => $isComplete,
            'progress' => $totalRows > 0
                ? round(((int) $job['PROCESSED_ROWS'] / $totalRows) * 100, 1)
                : 0,
        ];

        if ($isComplete && !empty($job['ERRORS_DATA'])) {
            $this->attachErrorsToResponse($response, $job);
        }

        if ($job['STATUS'] === ImportJobStatus::Error->value && empty($response['errorMessage'])) {
            $response['errorMessage'] = 'Произошла системная ошибка при обработке импорта';
        }

        return $response;
    }

    /**
     * Получает историю задач импорта с пагинацией
     *
     * @param int $page     Номер страницы (начиная с 1)
     * @param int $pageSize Размер страницы
     *
     * @return array{items: array, pagination: array}
     */
    public function getHistory(int $page, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;

        $countResult = ImportJobTable::getList([
            'select' => ['CNT'],
            'runtime' => [
                new ExpressionField('CNT', 'COUNT(*)'),
            ],
        ])->fetch();
        $totalCount = (int) ($countResult['CNT'] ?? 0);
        $totalPages = max(1, (int) ceil($totalCount / $pageSize));

        $dbResult = ImportJobTable::getList([
            'select' => [
                'ID', 'USER_ID', 'ENTITY_TYPE', 'STATUS',
                'TOTAL_ROWS', 'PROCESSED_ROWS', 'SUCCESS_COUNT', 'ERROR_COUNT',
                'CREATED_IDS',
                'CREATED_AT', 'STARTED_AT', 'FINISHED_AT',
            ],
            'order' => ['ID' => 'DESC'],
            'limit' => $pageSize,
            'offset' => $offset,
        ]);

        $jobs = [];
        $userIds = [];

        while ($row = $dbResult->fetch()) {
            $jobs[] = $row;
            $userIds[$row['USER_ID']] = true;
        }

        $userNames = $this->resolveUserNames(array_keys($userIds));

        $entityTitles = $this->getEntityTitles();

        $items = [];
        foreach ($jobs as $job) {
            $userId = (int) $job['USER_ID'];
            $items[] = [
                'id' => (int) $job['ID'],
                'userId' => $userId,
                'userName' => $userNames[$userId] ?? ('User #' . $userId),
                'entityType' => $job['ENTITY_TYPE'],
                'entityTitle' => $entityTitles[$job['ENTITY_TYPE']] ?? $job['ENTITY_TYPE'],
                'status' => $job['STATUS'],
                'totalRows' => (int) $job['TOTAL_ROWS'],
                'processedRows' => (int) $job['PROCESSED_ROWS'],
                'successCount' => (int) $job['SUCCESS_COUNT'],
                'errorCount' => (int) $job['ERROR_COUNT'],
                'createdIds' => !empty($job['CREATED_IDS'])
                    ? (json_decode($job['CREATED_IDS'], true) ?? [])
                    : [],
                'createdAt' => $job['CREATED_AT'] ? $job['CREATED_AT']->toString() : null,
                'startedAt' => $job['STARTED_AT'] ? $job['STARTED_AT']->toString() : null,
                'finishedAt' => $job['FINISHED_AT'] ? $job['FINISHED_AT']->toString() : null,
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * Десериализует ошибки задачи и добавляет в ответ
     */
    private function attachErrorsToResponse(array &$response, array $job): void
    {
        try {
            $errors = json_decode($job['ERRORS_DATA'], true);

            if (!is_array($errors)) {
                return;
            }

            $gridErrors = [];

            if (isset($errors['__exception__'])) {
                $exceptionErrors = $errors['__exception__'];
                unset($errors['__exception__']);

                if (is_array($exceptionErrors)) {
                    foreach ($exceptionErrors as $err) {
                        if (is_array($err) && isset($err['message'])) {
                            $response['errorMessage'] = $err['message'];
                            break;
                        }
                    }
                }
            }

            foreach ($errors as $rowIndex => $rowErrors) {
                if (!is_array($rowErrors)) {
                    continue;
                }
                $gridErrors[$rowIndex] = $rowErrors;
            }

            $response['errors'] = $gridErrors;
            $response['fieldToColumn'] = $this->getFieldToColumnMapping($job['ENTITY_TYPE']);
        } catch (\Throwable) {
        }
    }

    /**
     * Резолвит имена пользователей по массиву ID
     *
     * @param int[] $userIds
     * @return array<int, string>
     */
    private function resolveUserNames(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $userNames = [];
        $userResult = UserTable::getList([
            'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
            'filter' => ['=ID' => $userIds],
        ]);

        while ($user = $userResult->fetch()) {
            $fullName = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? ''));
            if ($fullName === '') {
                $fullName = $user['LOGIN'];
            }
            $userNames[(int) $user['ID']] = $fullName;
        }

        return $userNames;
    }

    /**
     * Возвращает маппинг ключей сущностей в названия
     *
     * @return array<string, string>
     */
    private function getEntityTitles(): array
    {
        $entityTitles = [];
        foreach (EntityRegistry::getAllForUi() as $key => $config) {
            $entityTitles[$key] = $config['title'];
        }

        return $entityTitles;
    }

    /**
     * Возвращает маппинг кодов полей CRM на ID колонок грида
     *
     * @param string $entityType
     * @return array<string, string>
     */
    private function getFieldToColumnMapping(string $entityType): array
    {
        $repository = EntityRegistry::createRepository($entityType);
        $fieldCodes = array_keys($repository->getFieldList());
        $mapping = [];

        foreach ($fieldCodes as $index => $code) {
            $mapping[$code] = 'COL_' . $index;
        }

        return $mapping;
    }
}
