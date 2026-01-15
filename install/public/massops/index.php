<?php

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

$APPLICATION->SetTitle('Массовые операции');

$APPLICATION->IncludeComponent(
    'rwb:massops.main',
    '',
    []
);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
