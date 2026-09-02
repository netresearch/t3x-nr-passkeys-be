<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

\defined('TYPO3') || die;
$tempColumns = [
    'passkeys' => [
        'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.label',
        'description' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.description',
        'config' => ['type' => 'none', 'renderType' => 'passkeyInfo'],
    ],
];
ExtensionManagementUtility::addTCAcolumns('be_users', $tempColumns);

// Add passkeys field after mfa in be_users form
ExtensionManagementUtility::addToAllTCAtypes('be_users', 'passkeys', '', 'after:mfa');
