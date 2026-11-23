<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);
use Netresearch\NrPasskeysBe\Middleware\PasskeyLoginCompletion;
use Netresearch\NrPasskeysBe\Middleware\PasskeySetupInterstitial;
use Netresearch\NrPasskeysBe\Middleware\PublicRouteResolver;

return [
    'backend' => [
        'netresearch/nr-passkeys-be/public-route-resolver' => [
            'target' => PublicRouteResolver::class,
            'after'  => ['typo3/cms-backend/backend-routing'],
            'before' => ['typo3/cms-backend/authentication'],
        ],
        // Wraps the core authentication: writes the markers of a passkey login
        // into the session the core creates for a fresh browser session.
        'netresearch/nr-passkeys-be/passkey-login-completion' => [
            'target' => PasskeyLoginCompletion::class,
            'after'  => ['typo3/cms-backend/backend-routing', 'netresearch/nr-passkeys-be/public-route-resolver'],
            'before' => ['typo3/cms-backend/authentication'],
        ],
        'netresearch/nr-passkeys-be/passkey-setup-interstitial' => [
            'target' => PasskeySetupInterstitial::class,
            'after'  => ['typo3/cms-backend/authentication'],
            'before' => ['typo3/cms-backend/site-resolver'],
        ],
    ],
];
