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
    ],
];
