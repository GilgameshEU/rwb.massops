<?php

namespace Rwb\Massops;

use RuntimeException;
use Rwb\Massops\Import\ContactImportService;
use Rwb\Massops\Import\ImportService;
use Rwb\Massops\Import\RowNormalizer;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Repository\EntityType;

/**
 * Реестр поддерживаемых CRM-сущностей
 *
 * Централизует знание о доступных сущностях, их отображении в UI
 * и создании соответствующих сервисов.
 */
final class EntityRegistry
{
    /**
     * Конфигурация сущностей
     */
    private const MAP = [
        'company' => [
            'title' => 'Компании',
            'icon' => 'building',
            'entityType' => EntityType::Company,
            'importClass' => null,
        ],
        'contact' => [
            'title' => 'Контакты',
            'icon' => 'person',
            'entityType' => EntityType::Contact,
            'importClass' => ContactImportService::class,
        ],
        'deal' => [
            'title' => 'Сделки',
            'icon' => 'handshake',
            'entityType' => EntityType::Deal,
            'importClass' => null,
        ],
    ];

    /**
     * Проверяет существование типа сущности
     */
    public static function has(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    /**
     * Возвращает конфигурацию сущности
     *
     * @throws RuntimeException
     */
    public static function getConfig(string $key): array
    {
        if (!self::has($key)) {
            throw new RuntimeException("Неизвестный тип сущности: $key");
        }

        return self::MAP[$key];
    }

    /**
     * Возвращает все сущности для отображения в UI
     *
     * @return array<string, array{title: string, icon: string}>
     */
    public static function getAllForUi(): array
    {
        $result = [];
        foreach (self::MAP as $key => $config) {
            $result[$key] = [
                'title' => $config['title'],
                'icon' => $config['icon'],
            ];
        }

        return $result;
    }

    /**
     * Создаёт репозиторий для указанного типа сущности
     */
    public static function createRepository(string $key): CrmRepository
    {
        $config = self::getConfig($key);

        return new CrmRepository($config['entityType']);
    }

    /**
     * Создаёт import-сервис для указанного типа сущности
     */
    public static function createImportService(string $key): ImportService
    {
        $config = self::getConfig($key);
        $repository = self::createRepository($key);

        if ($config['importClass'] !== null) {
            $importClass = $config['importClass'];

            return new $importClass($repository);
        }

        return new ImportService($repository, new RowNormalizer());
    }

    /**
     * Возвращает человекочитаемое название сущности
     */
    public static function getTitle(string $key): string
    {
        return self::getConfig($key)['title'];
    }
}
