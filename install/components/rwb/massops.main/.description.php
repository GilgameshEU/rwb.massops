<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentDescription = [
    'NAME' => Loc::getMessage('RWB_MASSOPS_MAIN_COMPONENT_NAME'),
    'DESCRIPTION' => Loc::getMessage('RWB_MASSOPS_MAIN_COMPONENT_DESCRIPTION'),
    'PATH' => [
        'ID' => 'rwb',
        'NAME' => Loc::getMessage('RWB_MASSOPS_MAIN_COMPONENT_VENDOR'),
    ],
];
