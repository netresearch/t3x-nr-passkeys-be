<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Do NOT add declare(strict_types=1) — TER cannot parse ext_emconf.php with it.

$EM_CONF[$_EXTKEY] = [
    'title' => 'Passkeys Backend Authentication',
    'description' => 'Passwordless TYPO3 backend authentication via Passkeys (WebAuthn/FIDO2). Enables one-click login with TouchID, FaceID, YubiKey, Windows Hello. By Netresearch.',
    'category' => 'be',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'stable',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'setup' => '12.4.0-14.99.99',
            'backend' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
