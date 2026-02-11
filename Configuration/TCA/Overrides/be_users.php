<?php

declare(strict_types=1);

\defined('TYPO3') or die();

$tempColumns = [
    'passkeys' => [
        'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.label',
        'config' => [
            'type' => 'none',
            'renderType' => 'passkeyInfo',
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_users', $tempColumns);

// Add passkeys field after mfa in be_users form
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    'passkeys',
    '',
    'after:mfa',
);
