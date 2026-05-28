<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanel;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Register passkey management panel in User Settings (Setup module).
// Must be in ext_tables.php because cms-setup/ext_tables.php initializes
// $GLOBALS['TYPO3_USER_SETTINGS'] (including showitem with mfaProviders).
// Registration in ext_localconf.php would be overwritten by the setup module.
//
// TYPO3 14 introduced UserSettingsSchema::getTca() which copies legacy columns
// verbatim into fake TCA columns. Because the legacy format has no 'config' key,
// SingleFieldContainer::inlineFieldShouldBeSkipped() does null + array → TypeError.
// For TYPO3 14+ register via the TCA-based API so the 'config' key is present.
$label = 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.title';

if ((new Typo3Version())->getMajorVersion() >= 14) {
    $GLOBALS['TCA']['be_users']['columns']['user_settings']['columns']['passkeys'] = [
        'label' => $label,
        'config' => [
            'type' => 'user',
            'userFunc' => PasskeySettingsPanel::class . '->render',
        ],
    ];
} else {
    $GLOBALS['TYPO3_USER_SETTINGS']['columns']['passkeys'] = [
        'type' => 'user',
        'userFunc' => PasskeySettingsPanel::class . '->render',
        'label' => $label,
    ];
}

ExtensionManagementUtility::addFieldsToUserSettings(
    'passkeys',
    'after:mfaProviders',
);

// Register CSH (Context-Sensitive Help) for the passkeys field in be_users records.
// addLLrefForTCAdescr() was removed in TYPO3 v13 — only call it on v12.
if (\method_exists(ExtensionManagementUtility::class, 'addLLrefForTCAdescr')) {
    ExtensionManagementUtility::addLLrefForTCAdescr(
        'be_users',
        'EXT:nr_passkeys_be/Resources/Private/Language/locallang_csh_be_users.xlf',
    );
}
