<?php

use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Extension::load("ui.buttons");

Asset::getInstance()->AddJS($this->GetFolder() . '/js/errorHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/gridHighlighter.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/tabManager.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/wizardManager.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/entitySelector.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/dropzoneHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/uploadHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/progressHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/importHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/templateHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/statsHandler.js');
Asset::getInstance()->AddJS($this->GetFolder() . '/js/main.js');

$entityTypes = $arResult['ENTITY_TYPES'] ?? [];
$currentEntityType = $arResult['CURRENT_ENTITY_TYPE'];
$hasData = $arResult['HAS_DATA'] ?? false;

$svgIcons = [
    'building' => '<svg viewBox="0 0 24 24"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/></svg>',
    'person' => '<svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
    'handshake' => '<svg viewBox="0 0 24 24"><path d="M12.22 19.85c-.18.18-.5.21-.71 0L3.2 11.54c-.2-.2-.2-.51 0-.71l2.12-2.12c.2-.2.51-.2.71 0l1.06 1.06 3.54-3.54-1.77-1.77c-.2-.2-.2-.51 0-.71l2.12-2.12c.2-.2.51-.2.71 0l8.31 8.31c.2.2.2.51 0 .71l-2.12 2.12c-.2.2-.51.2-.71 0l-1.06-1.06-3.54 3.54 1.77 1.77c.2.2.2.51 0 .71l-2.12 2.12zM14 5.83l-5 5 1.41 1.41 5-5L14 5.83zM8.83 11l-1.41 1.41 5 5 1.41-1.41-5-5z"/></svg>',
    'upload' => '<svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>',
    'check' => '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>',
    'search' => '<svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
];
?>

<script>
    BX.RwbMassops = {
        config: {
            entityTypes: <?= Json::encode($entityTypes) ?>,
            currentEntityType: <?= Json::encode($currentEntityType) ?>,
            hasData: <?= $hasData ? 'true' : 'false' ?>
        }
    };
</script>

<div class="rwb-massops">
    <!-- Табы навигации -->
    <div class="rwb-massops__tabs">
        <div class="rwb-massops__tab rwb-massops__tab--active" data-tab="import">
            Массовый импорт
        </div>
        <div class="rwb-massops__tab rwb-massops__tab--disabled" data-tab="dedup">
            Поиск дублей
            <span class="rwb-massops__tab-badge">скоро</span>
        </div>
        <div class="rwb-massops__tab" data-tab="stats">
            Статистика
        </div>
    </div>

    <!-- Контент табов -->
    <div class="rwb-massops__content">

        <!-- ===== ТАБ: Импорт ===== -->
        <div id="rwb-tab-import" class="rwb-massops__tab-content rwb-massops__tab-content--active">
            <div class="rwb-wizard">

                <!-- Степпер -->
                <div class="rwb-wizard__stepper">
                    <div class="rwb-wizard__step rwb-wizard__step--active" data-step="1">
                        <span class="rwb-wizard__step-number">1</span>
                        <span class="rwb-wizard__step-label">Выбор сущности</span>
                    </div>
                    <div class="rwb-wizard__step" data-step="2">
                        <span class="rwb-wizard__step-number">2</span>
                        <span class="rwb-wizard__step-label">Загрузка файла</span>
                    </div>
                    <div class="rwb-wizard__step" data-step="3">
                        <span class="rwb-wizard__step-number">3</span>
                        <span class="rwb-wizard__step-label">Предпросмотр и импорт</span>
                    </div>
                </div>

                <!-- Шаг 1: Выбор сущности -->
                <div class="rwb-wizard__panel rwb-wizard__panel--active" data-panel="1">
                    <div class="rwb-entity-cards">
                        <?php foreach ($entityTypes as $key => $entity): ?>
                            <div class="rwb-entity-card" data-entity="<?= htmlspecialchars($key) ?>">
                                <div class="rwb-entity-card__icon">
                                    <?= $svgIcons[$entity['icon']] ?? '' ?>
                                </div>
                                <div class="rwb-entity-card__title"><?= htmlspecialchars($entity['title']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rwb-wizard__actions">
                        <button class="ui-btn ui-btn-primary" id="rwb-wizard-next-1" disabled>Далее</button>
                    </div>
                </div>

                <!-- Шаг 2: Загрузка файла -->
                <div class="rwb-wizard__panel" data-panel="2">
                    <div class="rwb-dropzone" id="rwb-dropzone">
                        <div class="rwb-dropzone__icon">
                            <?= $svgIcons['upload'] ?>
                        </div>
                        <div class="rwb-dropzone__text">
                            Перетащите файл сюда или <strong>нажмите для выбора</strong>
                        </div>
                        <div class="rwb-dropzone__formats">Поддерживаемые форматы: .xlsx, .csv</div>
                        <div class="rwb-dropzone__file-info">
                            <span class="rwb-dropzone__file-name"></span>
                            <span class="rwb-dropzone__file-remove" title="Убрать файл">&times;</span>
                        </div>
                        <input type="file" id="rwb-import-file" accept=".csv,.xlsx" style="display: none;">
                    </div>

                    <a class="rwb-dropzone__template-link" id="rwb-import-template">Скачать шаблон импорта</a>

                    <div id="rwb-upload-errors-container"></div>

                    <div class="rwb-wizard__actions">
                        <button class="ui-btn ui-btn-light" id="rwb-wizard-back-2">Назад</button>
                        <button class="ui-btn ui-btn-primary" id="rwb-wizard-upload" disabled>Загрузить</button>
                    </div>
                </div>

                <!-- Шаг 3: Предпросмотр и импорт -->
                <div class="rwb-wizard__panel" data-panel="3">
                    <div class="rwb-preview">
                        <div class="rwb-preview__toolbar">
                            <div class="rwb-preview__info">
                                Загружено строк: <strong id="rwb-row-count"><?= count($arResult['GRID_ROWS']) ?></strong>
                            </div>

                            <label class="rwb-toggle">
                                <input type="checkbox" class="rwb-toggle__input" id="rwb-import-dry-run" checked>
                                <span class="rwb-toggle__track"></span>
                                <span class="rwb-toggle__label">Dry Run (тестовый режим)</span>
                            </label>

                            <button class="ui-btn ui-btn-primary ui-btn-sm" id="rwb-import-run">Проверить</button>
                        </div>

                        <div id="rwb-results-container"></div>

                        <div class="rwb-progress" id="rwb-import-progress" style="display: none;">
                            <div class="rwb-progress__bar">
                                <div class="rwb-progress__fill" id="rwb-progress-fill" style="width: 0%;"></div>
                            </div>
                            <div class="rwb-progress__text">
                                <span id="rwb-progress-label">Импорт...</span>
                                <span id="rwb-progress-percent">0%</span>
                            </div>
                            <div class="rwb-progress__stats" id="rwb-progress-stats"></div>
                        </div>

                        <div id="rwb-grid-container">
                            <?php
                            $APPLICATION->IncludeComponent(
                                'bitrix:main.ui.grid',
                                '',
                                [
                                    'GRID_ID' => 'RWB_MASSOPS_GRID',
                                    'COLUMNS' => $arResult['GRID_COLUMNS'],
                                    'ROWS' => $arResult['GRID_ROWS'],
                                    'SHOW_ROW_CHECKBOXES' => false,
                                    'AJAX_MODE' => 'Y',
                                    'AJAX_OPTION_JUMP' => 'N',
                                    'AJAX_OPTION_HISTORY' => 'N',
                                    'ALLOW_COLUMNS_SORT' => false,
                                    'ALLOW_COLUMNS_RESIZE' => true,
                                ]
                            );
                            ?>
                        </div>
                    </div>

                    <div class="rwb-wizard__actions">
                        <button class="ui-btn ui-btn-light" id="rwb-wizard-back-3">Назад</button>
                        <button class="ui-btn ui-btn-light-danger" id="rwb-import-clear">Очистить и начать заново</button>
                    </div>
                </div>

            </div>
        </div>

        <!-- ===== ТАБ: Поиск дублей ===== -->
        <div id="rwb-tab-dedup" class="rwb-massops__tab-content">
            <div class="rwb-placeholder">
                <div class="rwb-placeholder__icon">
                    <?= $svgIcons['search'] ?>
                </div>
                <div class="rwb-placeholder__title">Поиск дублей</div>
                <div class="rwb-placeholder__text">Функционал находится в разработке и скоро будет доступен.</div>
            </div>
        </div>

        <!-- ===== ТАБ: Статистика ===== -->
        <div id="rwb-tab-stats" class="rwb-massops__tab-content">
            <div class="rwb-stats">
                <div class="rwb-stats__toolbar">
                    <div class="rwb-stats__title">История операций импорта</div>
                    <button class="ui-btn ui-btn-light ui-btn-sm" id="rwb-stats-refresh">Обновить</button>
                </div>
                <div class="rwb-stats__loading" id="rwb-stats-loading" style="display: none;">
                    Загрузка данных...
                </div>
                <div class="rwb-stats__empty" id="rwb-stats-empty" style="display: none;">
                    Нет данных об операциях импорта.
                </div>
                <div class="rwb-stats__table-wrap" id="rwb-stats-table-wrap" style="display: none;">
                    <table class="rwb-stats__table" id="rwb-stats-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Пользователь</th>
                                <th>Сущность</th>
                                <th>Статус</th>
                                <th>Всего</th>
                                <th>Успешно</th>
                                <th>Ошибок</th>
                                <th>Добавлено (ID)</th>
                                <th>Создано</th>
                                <th>Начало</th>
                                <th>Завершено</th>
                            </tr>
                        </thead>
                        <tbody id="rwb-stats-tbody"></tbody>
                    </table>
                </div>
                <div class="rwb-stats__pagination" id="rwb-stats-pagination" style="display: none;"></div>
            </div>
        </div>

    </div>
</div>
