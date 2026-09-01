<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);
use Netresearch\NrPasskeysBe\Controller\LoginController;

return [
    // Login flow endpoints (anonymous - no authentication required)
    'passkeys_login_options' => [
        'path' => '/passkeys/login/options',
        'target' => LoginController::class . '::optionsAction',
        'methods' => ['POST'],
        'access' => 'public',
    ],
    'passkeys_login_verify' => [
        'path' => '/passkeys/login/verify',
        'target' => LoginController::class . '::verifyAction',
        'methods' => ['POST'],
        'access' => 'public',
    ],
];
