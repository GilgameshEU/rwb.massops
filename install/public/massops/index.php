<?php

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

// Одноразовая установка пункта меню в левое меню Bitrix24
// \Rwb\Massops\Menu\MenuInstaller::installMenuOnce();

$APPLICATION->setTitle('Массовые операции');

$APPLICATION->IncludeComponent(
    'rwb:massops.main',
    '',
    []
);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
