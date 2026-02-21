<?php

namespace Rwb\Massops;

use RuntimeException;
use Rwb\Massops\Import\CompanyImportService;
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
 *
 * Поддерживает динамическую регистрацию сущностей через register()
 * из внешних модулей или обработчиков событий.
 */
final class EntityRegistry
{
    /**
     * Динамический реестр сущностей.
     * Заполняется при первом обращении из DEFAULT_MAP, а затем может расширяться через register().
     *
     * @var array<string, array{title: string, icon: string, entityType: EntityType, importClass: string|null, disabled: bool}>|null
     */
    private static ?array $map = null;

    /**
     * Встроенные сущности по умолчанию
     */
    private const DEFAULT_MAP = [
        'company' => [
            'title' => 'Компании',
            'icon' => 'building',
            'entityType' => EntityType::Company,
            'importClass' => CompanyImportService::class,
            'listUrl' => '/crm/company/list/',
            'disabled' => false,
        ],
        'contact' => [
            'title' => 'Контакты',
            'icon' => 'person',
            'entityType' => EntityType::Contact,
            'importClass' => ContactImportService::class,
            'listUrl' => '/crm/contact/list/',
            'disabled' => false,
        ],
        'deal' => [
            'title' => 'Сделки',
            'icon' => 'handshake',
            'entityType' => EntityType::Deal,
            'importClass' => null,
            'listUrl' => '/crm/deal/list/',
            'disabled' => true, // Временно отключено
        ],
    ];

    /**
     * Регистрирует новую сущность или переопределяет существующую.
     *
     * Позволяет внешним модулям или плагинам добавлять собственные типы сущностей.
     *
     * @param string $key    Ключ сущности (например 'invoice')
     * @param array  $config Конфигурация:
     *                       - title (string)           — человекочитаемое название
     *                       - icon (string)            — ключ SVG-иконки
     *                       - entityType (EntityType)  — тип сущности CRM
     *                       - importClass (string|null) — FQCN класса ImportService
     *                       - disabled (bool)          — скрыть в UI
     *
     * @throws RuntimeException если конфигурация неполная
     */
    public static function register(string $key, array $config): void
    {
        if (empty($key)) {
            throw new RuntimeException('Ключ сущности не может быть пустым');
        }

        $required = ['title', 'icon', 'entityType'];
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new RuntimeException("Конфигурация сущности '{$key}' не содержит обязательного поля '{$field}'");
            }
        }

        $config['importClass'] ??= null;
        $config['disabled'] ??= false;

        self::getMap()[$key] = $config;
    }

    /**
     * Проверяет существование типа сущности
     */
    public static function has(string $key): bool
    {
        return isset(self::getMap()[$key]);
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

        return self::getMap()[$key];
    }

    /**
     * Возвращает все сущности для отображения в UI
     *
     * @return array<string, array{title: string, icon: string, listUrl: string, disabled: bool}>
     */
    public static function getAllForUi(): array
    {
        $result = [];
        foreach (self::getMap() as $key => $config) {
            $result[$key] = [
                'title' => $config['title'],
                'icon' => $config['icon'],
                'listUrl' => $config['listUrl'] ?? '',
                'disabled' => $config['disabled'] ?? false,
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

    /**
     * Возвращает (ленивую) ссылку на реестр.
     * При первом вызове инициализирует реестр из DEFAULT_MAP.
     *
     * @return array<string, array>
     */
    private static function &getMap(): array
    {
        if (self::$map === null) {
            self::$map = self::DEFAULT_MAP;
        }

        return self::$map;
    }
}
