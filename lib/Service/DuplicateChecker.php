<?php

namespace Rwb\Massops\Service;

use Rwb\Massops\Import\ImportError;

/**
 * Сервис проверки дубликатов
 *
 * Делегирует логику поиска дублей стратегии (DuplicateStrategy).
 * По умолчанию использует InnDuplicateStrategy.
 *
 * Позволяет подменить стратегию через конструктор для поддержки
 * других типов сущностей (контакты по телефону/email и т.д.).
 */
class DuplicateChecker
{
    private DuplicateStrategy $strategy;

    /**
     * @param DuplicateStrategy|null $strategy Стратегия поиска дублей (по умолчанию — InnDuplicateStrategy)
     */
    public function __construct(?DuplicateStrategy $strategy = null)
    {
        $this->strategy = $strategy ?? new InnDuplicateStrategy();
    }

    /**
     * Проверяет дубликаты внутри файла
     *
     * @param array  $rows         Строки данных
     * @param string $innFieldCode Код поля-идентификатора (ИНН для компаний)
     *
     * @return array<int, ImportError>
     */
    public function checkFileInternalDuplicates(array $rows, string $innFieldCode): array
    {
        return $this->strategy->checkFileInternalDuplicates(
            $rows,
            ['innFieldCode' => $innFieldCode]
        );
    }

    /**
     * Проверяет дубликаты в CRM
     *
     * @param array $rows              Нормализованные строки
     * @param string $innFieldCode     Код поля-идентификатора
     * @param array $excludeRowIndexes (не используется, оставлен для обратной совместимости)
     *
     * @return array<int, ImportError>
     */
    public function checkCrmDuplicates(array $rows, string $innFieldCode, array $excludeRowIndexes = []): array
    {
        $validRowIndexes = array_keys($rows);

        return $this->strategy->checkCrmDuplicates(
            $rows,
            $validRowIndexes,
            ['innFieldCode' => $innFieldCode]
        );
    }

    /**
     * Возвращает код поля ИНН (делегирует стратегии, если та его поддерживает)
     */
    public function getInnFieldCode(): ?string
    {
        if ($this->strategy instanceof InnDuplicateStrategy) {
            return $this->strategy->getInnFieldCode();
        }

        return null;
    }
}
