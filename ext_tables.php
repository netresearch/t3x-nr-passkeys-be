<?php

declare(strict_types=1);

use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanel;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register passkey management panel in User Settings (Setup module).
// Must be in ext_tables.php because cms-setup/ext_tables.php initializes
// $GLOBALS['TYPO3_USER_SETTINGS'] (including showitem with mfaProviders).
// Registration in ext_localconf.php would be overwritten by the setup module.
$GLOBALS['TYPO3_USER_SETTINGS']['columns']['passkeys'] = [
    'type' => 'user',
    'userFunc' => PasskeySettingsPanel::class . '->render',
    'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.title',
];

ExtensionManagementUtility::addFieldsToUserSettings(
    'passkeys',
    'after:mfaProviders',
);
