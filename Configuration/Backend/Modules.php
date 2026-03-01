<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Controller\AdminModuleController;

return [
    'admin_passkeys' => [
        'parent' => 'system',
        'position' => ['after' => 'backend_user_management'],
        'access' => 'admin',
        'iconIdentifier' => 'passkeys-be-module',
        'labels' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_module.xlf',
        'routes' => [
            '_default' => [
                'target' => AdminModuleController::class . '::dashboardAction',
            ],
            'help' => [
                'target' => AdminModuleController::class . '::helpAction',
            ],
        ],
    ],
];
