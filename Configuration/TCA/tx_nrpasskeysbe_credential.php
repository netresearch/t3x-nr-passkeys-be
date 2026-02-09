<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential',
        'label' => 'label',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'created_at DESC',
        'rootLevel' => 1,
        'iconfile' => 'EXT:nr_passkeys_be/Resources/Public/Icons/credential.svg',
        'hideTable' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'be_user' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'readOnly' => true,
            ],
        ],
        'credential_id' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.credential_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'label' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.label',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 128,
                'eval' => 'trim',
            ],
        ],
        'sign_count' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.sign_count',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'created_at' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.created_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'last_used_at' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.last_used_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'revoked_at' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.revoked_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'revoked_by' => [
            'label' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysbe_credential.revoked_by',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'be_user, credential_id, label, sign_count, created_at, last_used_at, revoked_at, revoked_by',
        ],
    ],
];
