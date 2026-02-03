<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

/**
 * ORM-таблица очереди импорта
 *
 * Таблица: b_rwb_massops_import_queue
 */
class ImportJobTable extends Entity\DataManager
{
    public static function getTableName(): string
    {
        return 'b_rwb_massops_import_queue';
    }

    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new Entity\IntegerField('USER_ID', [
                'required' => true,
            ]),
            new Entity\StringField('ENTITY_TYPE', [
                'required' => true,
                'size' => 50,
            ]),
            new Entity\StringField('STATUS', [
                'required' => true,
                'size' => 20,
                'default_value' => ImportJobStatus::Pending->value,
            ]),
            new Entity\IntegerField('TOTAL_ROWS', [
                'required' => true,
            ]),
            new Entity\IntegerField('PROCESSED_ROWS', [
                'default_value' => 0,
            ]),
            new Entity\IntegerField('SUCCESS_COUNT', [
                'default_value' => 0,
            ]),
            new Entity\IntegerField('ERROR_COUNT', [
                'default_value' => 0,
            ]),
            new Entity\TextField('ERRORS_DATA'),
            new Entity\TextField('CREATED_IDS'),
            new Entity\TextField('IMPORT_DATA', [
                'required' => true,
            ]),
            new Entity\DatetimeField('CREATED_AT', [
                'required' => true,
                'default_value' => static fn() => new DateTime(),
            ]),
            new Entity\DatetimeField('STARTED_AT'),
            new Entity\DatetimeField('FINISHED_AT'),
        ];
    }
}
