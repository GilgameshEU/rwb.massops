<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

\Bitrix\Main\UI\Extension::load('ui.notification');
?>

<div class="rwb-massops">
    <h2>Массовые операции</h2>

    <button id="rwb-test-btn" type="button">
        AJAX test
    </button>
</div>

<script>
    BX.message({
        RWB_MASSOPS_COMPONENT: 'rwb:massops.main',
        RWB_MASSOPS_SIGNED_PARAMS: '<?= CUtil::JSEscape($this->getComponent()->getSignedParameters()) ?>'
    });
</script>
