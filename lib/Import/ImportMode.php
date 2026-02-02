<?php

namespace Rwb\Massops\Import;

/**
 * Режим выполнения импорта
 */
enum ImportMode: string
{
    case Import = 'import';
    case DryRun = 'dry_run';
}
