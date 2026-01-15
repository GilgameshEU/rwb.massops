<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

\Bitrix\Main\UI\Extension::load(['ui.grid', 'ui.notification']);
?>

<div class="rwb-import">
    <input type="file" id="rwb-import-file" accept=".csv,.xlsx">
    <button id="rwb-import-btn">Загрузить</button>
</div>

<div id="rwb-grid-container"></div>

<script>
    BX.message({
        RWB_COMPONENT: 'rwb:massops.main'
    });
</script>
