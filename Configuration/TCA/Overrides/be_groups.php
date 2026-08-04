<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

\defined('TYPO3') || die();

$tempColumns = [
    'passkey_enforcement' => [
        'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement',
        'description' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement.off', 'value' => 'off'],
                ['label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement.encourage', 'value' => 'encourage'],
                ['label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement.required', 'value' => 'required'],
                ['label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_enforcement.enforced', 'value' => 'enforced'],
            ],
            'default' => 'off',
        ],
    ],
    'passkey_grace_period_days' => [
        'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_grace_period_days',
        'description' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.passkey_grace_period_days.description',
        'config' => [
            'type' => 'number',
            'size' => 5,
            'range' => ['lower' => 1, 'upper' => 365],
            'default' => 14,
        ],
        'displayCond' => 'FIELD:passkey_enforcement:=:required',
    ],
];

ExtensionManagementUtility::addTCAcolumns('be_groups', $tempColumns);

ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    '--div--;LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:be_groups.tab.passkeys,passkey_enforcement,passkey_grace_period_days',
);
