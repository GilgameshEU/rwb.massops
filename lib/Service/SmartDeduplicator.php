<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Crm\Service;
use Bitrix\Main\LoaderException;

class SmartDeduplicator
{
    /**
     * Метод для агента с логированием начала и конца процесса
     *
     * @throws ArgumentException
     * @throws LoaderException
     */
    public static function runAgent(
        int $entityTypeId,
        string $stageId,
        string $userFieldName,
        int $limit = 100,
        bool $dryRun = true,
        int $lastId = 0
    ): string {
        if (!Loader::includeModule('crm')) {
            return self::getAgentName($entityTypeId, $stageId, $userFieldName, $limit, $dryRun, $lastId);
        }

        $container = Service\Container::getInstance();
        $factory = $container->getFactory($entityTypeId);

        if (!$factory) {
            return "";
        }

        $logPath = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/rwb.massops/dedupe_{$entityTypeId}.log";
        $mode = $dryRun ? "[DRY-RUN]" : "[DELETE]";

        if ($lastId === 0) {
            $startLine = str_repeat("=", 50) . "\n";
            $startLine .= sprintf("%s %s >>> СТАРТ ПРОЦЕССА\n", date('Y-m-d H:i:s'), $mode);
            $startLine .= sprintf("Сущность: %d | Стадия: %s | Поле: %s\n", $entityTypeId, $stageId, $userFieldName);
            $startLine .= str_repeat("=", 50) . "\n";
            file_put_contents($logPath, $startLine, FILE_APPEND);
        }

        $items = $factory->getItems([
            'select' => ['ID', 'CREATED_TIME', $userFieldName],
            'filter' => [
                '=STAGE_ID' => $stageId,
                '!=' . $userFieldName => false,
                '>ID' => $lastId,
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => $limit,
        ]);

        $currentLastId = $lastId;
        $foundInBatch = false;

        foreach ($items as $item) {
            $foundInBatch = true;
            $currentLastId = $item->getId();
            $val = $item->get($userFieldName);
            $itemTime = $item->get('CREATED_TIME');

            $originals = $factory->getItems([
                'select' => ['ID'],
                'filter' => [
                    '=' . $userFieldName => $val,
                    '=STAGE_ID' => $stageId,
                    [
                        'LOGIC' => 'OR',
                        ['<CREATED_TIME' => $itemTime],
                        [
                            '=CREATED_TIME' => $itemTime,
                            '<ID' => $item->getId(),
                        ],
                    ],
                ],
                'limit' => 1,
            ]);

            if (!empty($originals)) {
                $originalItem = reset($originals);
                $msg = sprintf(
                    "%s %s ID: %d -> Дубль. Оригинал: %d (Value: %s)\n",
                    date('Y-m-d H:i:s'),
                    $mode,
                    $item->getId(),
                    $originalItem->getId(),
                    $val
                );
                file_put_contents($logPath, $msg, FILE_APPEND);

                if (!$dryRun) {
                    $item->delete();
                }
            }
        }

        if (!$foundInBatch) {
            $endLine = str_repeat("-", 50) . "\n";
            $endLine .= sprintf("%s %s <<< ЗАВЕРШЕНО. Все записи обработаны.\n", date('Y-m-d H:i:s'), $mode);
            $endLine .= str_repeat("-", 50) . "\n\n";
            file_put_contents($logPath, $endLine, FILE_APPEND);

            return "";
        }

        return self::getAgentName($entityTypeId, $stageId, $userFieldName, $limit, $dryRun, $currentLastId);
    }

    private static function getAgentName($entityTypeId, $stageId, $userFieldName, $limit, $dryRun, $lastId = 0): string
    {
        $dryRunStr = $dryRun ? 'true' : 'false';

        return "\\Rwb\\Massops\\Service\\SmartDeduplicator::runAgent($entityTypeId, '$stageId', '$userFieldName', $limit, $dryRunStr, $lastId);";
    }
}
