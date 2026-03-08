<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

/**
 * @var string $mid — module ID
 * @var CMain $APPLICATION
 */

$moduleId = 'rwb.massops';
$request = Application::getInstance()->getContext()->getRequest();

Loc::loadMessages(__FILE__);

// Проверка прав
if (!$USER->isAdmin()) {
    $APPLICATION->authForm(Loc::getMessage('ACCESS_DENIED'));
}

// Список групп для селекта доступа
$accessGroups = [0 => Loc::getMessage('RWB_MASSOPS_OPT_ACCESS_GROUP_NONE')];
$rsGroups = \CGroup::getList('id', 'asc', ['ACTIVE' => 'Y']);
while ($group = $rsGroups->fetch()) {
    $accessGroups[(int) $group['ID']] = $group['NAME'];
}

// Описание вкладок и полей
$tabs = [
    [
        'DIV' => 'import',
        'TAB' => Loc::getMessage('RWB_MASSOPS_OPT_TAB_IMPORT'),
        'TITLE' => Loc::getMessage('RWB_MASSOPS_OPT_TAB_IMPORT_TITLE'),
        'OPTIONS' => [
            Loc::getMessage('RWB_MASSOPS_OPT_SECTION_QUEUE'),
            [
                'queue_batch_size',
                Loc::getMessage('RWB_MASSOPS_OPT_BATCH_SIZE'),
                '50',
                ['text', 6],
            ],
            [
                'queue_agent_interval',
                Loc::getMessage('RWB_MASSOPS_OPT_AGENT_INTERVAL'),
                '30',
                ['text', 6],
            ],
            [
                'max_file_size_mb',
                Loc::getMessage('RWB_MASSOPS_OPT_MAX_FILE_SIZE'),
                '10',
                ['text', 6],
            ],
            Loc::getMessage('RWB_MASSOPS_OPT_SECTION_PHONE'),
            [
                'phone_default_country',
                Loc::getMessage('RWB_MASSOPS_OPT_PHONE_COUNTRY'),
                'RU',
                ['text', 4],
            ],
            [
                'multifield_delimiter',
                Loc::getMessage('RWB_MASSOPS_OPT_DELIMITER'),
                ',',
                ['text', 3],
            ],
            Loc::getMessage('RWB_MASSOPS_OPT_SECTION_ACCESS'),
            [
                'access_group_id',
                Loc::getMessage('RWB_MASSOPS_OPT_ACCESS_GROUP'),
                '0',
                ['select', $accessGroups],
            ],
        ],
    ],
];

// Сохранение
if (
    $request->isPost()
    && $request->getPost('save') !== null
    && check_bitrix_sessid()
) {
    foreach ($tabs as $tab) {
        foreach ($tab['OPTIONS'] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionName = $option[0];
            $optionDefault = $option[2];
            $value = $request->getPost($optionName);

            if ($value !== null) {
                Option::set($moduleId, $optionName, $value);
            } else {
                Option::set($moduleId, $optionName, $optionDefault);
            }
        }
    }

    // Обновление интервала агента если он изменился
    $agentInterval = (int) Option::get($moduleId, 'queue_agent_interval', 30);
    if ($agentInterval > 0) {
        $agentName = '\\Rwb\\Massops\\Queue\\ImportAgent::process();';
        $dbAgent = \CAgent::GetList(
            [],
            ['NAME' => $agentName, 'MODULE_ID' => $moduleId]
        )->Fetch();

        if ($dbAgent) {
            \CAgent::Update($dbAgent['ID'], [
                'AGENT_INTERVAL' => $agentInterval,
            ]);
        }
    }
}

// Рендер вкладок
$tabControl = new CAdminTabControl('tabControl', $tabs);

$tabControl->begin();
?>
<form method="post" action="<?= $APPLICATION->getCurPage() ?>?mid=<?= htmlspecialcharsbx($moduleId) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php
    foreach ($tabs as $tab) {
        $tabControl->beginNextTab();

        foreach ($tab['OPTIONS'] as $option) {
            // Заголовок секции
            if (!is_array($option)) {
                ?>
                <tr class="heading">
                    <td colspan="2"><?= $option ?></td>
                </tr>
                <?php
                continue;
            }

            $optionName = $option[0];
            $optionLabel = $option[1];
            $optionDefault = $option[2];
            $optionType = $option[3];

            $value = Option::get($moduleId, $optionName, $optionDefault);
            ?>
            <tr>
                <td width="40%">
                    <label for="<?= htmlspecialcharsbx($optionName) ?>"><?= $optionLabel ?></label>
                </td>
                <td width="60%">
                    <?php if ($optionType[0] === 'text'): ?>
                        <input
                            type="text"
                            size="<?= (int) $optionType[1] ?>"
                            id="<?= htmlspecialcharsbx($optionName) ?>"
                            name="<?= htmlspecialcharsbx($optionName) ?>"
                            value="<?= htmlspecialcharsbx($value) ?>"
                        >
                    <?php elseif ($optionType[0] === 'select'): ?>
                        <select id="<?= htmlspecialcharsbx($optionName) ?>" name="<?= htmlspecialcharsbx($optionName) ?>">
                            <?php foreach ($optionType[1] as $optVal => $optLabel): ?>
                                <option value="<?= htmlspecialcharsbx((string) $optVal) ?>"<?= ((string) $value === (string) $optVal) ? ' selected' : '' ?>>
                                    <?= htmlspecialcharsbx($optLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }

    $tabControl->buttons();
    ?>
    <input type="submit" name="save" value="<?= Loc::getMessage('RWB_MASSOPS_OPT_SAVE') ?>" class="adm-btn-save">
    <?php
    $tabControl->end();
    ?>
</form>
