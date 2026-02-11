<?php

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Controller\AdminController;
use Netresearch\NrPasskeysBe\Controller\ManagementController;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessLifetime;

return [
    // Management write operations -- sudo mode group 'passkey_manage'
    'passkeys_manage_registration_options' => [
        'path' => '/passkeys/manage/registration/options',
        'target' => ManagementController::class . '::registrationOptionsAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_manage',
            'lifetime' => AccessLifetime::medium,
        ],
    ],
    'passkeys_manage_registration_verify' => [
        'path' => '/passkeys/manage/registration/verify',
        'target' => ManagementController::class . '::registrationVerifyAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_manage',
            'lifetime' => AccessLifetime::medium,
        ],
    ],
    'passkeys_manage_rename' => [
        'path' => '/passkeys/manage/rename',
        'target' => ManagementController::class . '::renameAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_manage',
            'lifetime' => AccessLifetime::medium,
        ],
    ],
    'passkeys_manage_remove' => [
        'path' => '/passkeys/manage/remove',
        'target' => ManagementController::class . '::removeAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_manage',
            'lifetime' => AccessLifetime::medium,
        ],
    ],

    // Management read -- no sudo mode (low risk)
    'passkeys_manage_list' => [
        'path' => '/passkeys/manage/list',
        'target' => ManagementController::class . '::listAction',
        'methods' => ['GET'],
    ],

    // Admin write operations -- sudo mode group 'passkey_admin'
    'passkeys_admin_remove' => [
        'path' => '/passkeys/admin/remove',
        'target' => AdminController::class . '::removeAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_admin',
            'lifetime' => AccessLifetime::medium,
        ],
    ],
    'passkeys_admin_unlock' => [
        'path' => '/passkeys/admin/unlock',
        'target' => AdminController::class . '::unlockAction',
        'methods' => ['POST'],
        'sudoMode' => [
            'group' => 'passkey_admin',
            'lifetime' => AccessLifetime::medium,
        ],
    ],

    // Admin read -- no sudo mode (low risk)
    'passkeys_admin_list' => [
        'path' => '/passkeys/admin/list',
        'target' => AdminController::class . '::listAction',
        'methods' => ['GET'],
    ],
];
