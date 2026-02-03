<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

/**
 * @var CMain $APPLICATION
 */

$request = Application::getInstance()->getContext()->getRequest();
?>

<form action="<?= $request->getRequestedPage() ?>" name="form1" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANG ?>">
    <input type="hidden" name="id" value="rwb.massops">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <?php if ($exception = $APPLICATION->getException()): ?>
        <?php CAdminMessage::showMessage([
            'TYPE' => 'ERROR',
            'MESSAGE' => Loc::getMessage('MOD_INST_ERR'),
            'DETAILS' => $exception->getString(),
            'HTML' => true,
        ]); ?>
    <?php else: ?>
        <table>
            <tr>
                <td>
                    <input type="checkbox" name="save_data" id="save_data" value="Y" checked>
                    <label for="save_data"><?= Loc::getMessage('RWB_MASSOPS_SAVE_DATA') ?></label>
                </td>
            </tr>
            <tr>
                <td>
                    <input type="checkbox" name="save_option" id="save_option" value="Y" checked>
                    <label for="save_option"><?= Loc::getMessage('RWB_MASSOPS_SAVE_OPTIONS') ?></label>
                </td>
            </tr>
        </table>
    <?php endif; ?>

    <input type="submit" name="inst" value="<?= Loc::getMessage('RWB_MASSOPS_UNINSTALL_SUBMIT') ?>">
</form>
