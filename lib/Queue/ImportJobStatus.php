<?php

namespace Rwb\Massops\Queue;

/**
 * Статус задачи импорта
 */
enum ImportJobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Error = 'error';
}
