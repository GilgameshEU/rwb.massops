<?php

namespace Rwb\Massops\Queue;

use Bitrix\Main\Entity;

/**
 * ORM-таблица строк задачи импорта
 *
 * Каждая строка из XLSX хранится отдельной записью.
 * Это позволяет агенту выбирать только нужную пачку строк
 * вместо загрузки всего набора данных в память.
 *
 * Таблица: b_rwb_massops_import_rows
 */
class ImportJobRowTable extends Entity\DataManager
{
    public static function getTableName(): string
    {
        return 'b_rwb_massops_import_rows';
    }

    public static function getMap(): array
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new Entity\IntegerField('JOB_ID', [
                'required' => true,
            ]),
            new Entity\IntegerField('ROW_INDEX', [
                'required' => true,
            ]),
            new Entity\TextField('ROW_DATA', [
                'required' => true,
            ]),
        ];
    }
}
