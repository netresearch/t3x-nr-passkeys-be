<?php

declare(strict_types=1);

return [
    'backend' => [
        'netresearch/nr-passkeys-be/public-route-resolver' => [
            'target' => \Netresearch\NrPasskeysBe\Middleware\PublicRouteResolver::class,
            'after' => [
                'typo3/cms-backend/backend-routing',
            ],
            'before' => [
                'typo3/cms-backend/authentication',
            ],
        ],
        'netresearch/nr-passkeys-be/passkey-setup-interstitial' => [
            'target' => \Netresearch\NrPasskeysBe\Middleware\PasskeySetupInterstitial::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
        ],
    ],
];
