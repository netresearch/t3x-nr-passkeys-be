<?php

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Controller\AdminController;
use Netresearch\NrPasskeysBe\Controller\LoginController;
use Netresearch\NrPasskeysBe\Controller\ManagementController;

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

    // Management endpoints (authenticated)
    'passkeys_manage_registration_options' => [
        'path' => '/passkeys/manage/registration/options',
        'target' => ManagementController::class . '::registrationOptionsAction',
        'methods' => ['POST'],
    ],
    'passkeys_manage_registration_verify' => [
        'path' => '/passkeys/manage/registration/verify',
        'target' => ManagementController::class . '::registrationVerifyAction',
        'methods' => ['POST'],
    ],
    'passkeys_manage_list' => [
        'path' => '/passkeys/manage/list',
        'target' => ManagementController::class . '::listAction',
        'methods' => ['GET'],
    ],
    'passkeys_manage_rename' => [
        'path' => '/passkeys/manage/rename',
        'target' => ManagementController::class . '::renameAction',
        'methods' => ['POST'],
    ],
    'passkeys_manage_remove' => [
        'path' => '/passkeys/manage/remove',
        'target' => ManagementController::class . '::removeAction',
        'methods' => ['POST'],
    ],

    // Admin endpoints (admin-only)
    'passkeys_admin_list' => [
        'path' => '/passkeys/admin/list',
        'target' => AdminController::class . '::listAction',
        'methods' => ['GET'],
    ],
    'passkeys_admin_remove' => [
        'path' => '/passkeys/admin/remove',
        'target' => AdminController::class . '::removeAction',
        'methods' => ['POST'],
    ],
    'passkeys_admin_unlock' => [
        'path' => '/passkeys/admin/unlock',
        'target' => AdminController::class . '::unlockAction',
        'methods' => ['POST'],
    ],
];
