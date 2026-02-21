<?php

namespace Rwb\Massops\Import;

/**
 * Коды ошибок импорта
 *
 * Централизованный реестр всех кодов ошибок модуля.
 * Используется при создании ImportError вместо магических строк.
 */
enum ImportErrorCode: string
{
    // ── Файл ──────────────────────────────────────────────────────────────
    case FileInvalid = 'FILE_INVALID';

    // ── Заголовок файла ───────────────────────────────────────────────────
    case HeaderInvalid  = 'INVALID';
    case HeaderNotFound = 'NOT_FOUND';

    // ── Типы и форматы полей ──────────────────────────────────────────────
    case InvalidInteger  = 'INVALID_INTEGER';
    case InvalidDouble   = 'INVALID_DOUBLE';
    case InvalidBoolean  = 'INVALID_BOOLEAN';
    case InvalidDate     = 'INVALID_DATE';
    case InvalidDatetime = 'INVALID_DATETIME';
    case InvalidUrl      = 'INVALID_URL';
    case InvalidMoney    = 'INVALID_MONEY';
    case InvalidPhone    = 'INVALID_PHONE';
    case InvalidEnum     = 'INVALID_ENUM';
    case InvalidCrmRef   = 'INVALID_CRM_REF';

    // ── Резолюция связанных сущностей ─────────────────────────────────────
    case UserNotFound      = 'USER_NOT_FOUND';
    case IblockNotFound    = 'IBLOCK_NOT_FOUND';
    case CrmEntityNotFound = 'CRM_ENTITY_NOT_FOUND';

    // ── Дубликаты ─────────────────────────────────────────────────────────
    case DuplicateInFile = 'DUPLICATE_IN_FILE';
    case DuplicateInCrm  = 'DUPLICATE_IN_CRM';

    // ── Общие ─────────────────────────────────────────────────────────────
    case Required            = 'REQUIRED';
    case Invalid             = 'INVALID_VALUE';
    case ProcessingException = 'PROCESSING_EXCEPTION';
}
