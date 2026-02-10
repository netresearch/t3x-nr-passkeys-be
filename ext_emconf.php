<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Passkeys Backend Authentication',
    'description' => 'Passwordless TYPO3 backend authentication via Passkeys (WebAuthn/FIDO2). Enables one-click login with TouchID, FaceID, YubiKey, Windows Hello. By Netresearch.',
    'category' => 'be',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'typo3@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'setup' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
