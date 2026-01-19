<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

\Bitrix\Main\UI\Extension::load("ui.buttons");
?>

<div style="margin-bottom: 20px;">
    <input type="file" id="rwb-import-file" accept=".csv,.xlsx">
    <button class="ui-btn ui-btn-primary" id="rwb-import-upload">Загрузить файл</button>
    <button class="ui-btn" id="rwb-import-template">Скачать шаблон</button>
    <button class="ui-btn ui-btn-success" id="rwb-import-run">Импортировать компании</button>
    <button class="ui-btn ui-btn-light-danger" id="rwb-import-clear">Очистить</button>
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
