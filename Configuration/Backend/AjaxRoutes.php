<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Controller\AdminController;
use Netresearch\NrPasskeysBe\Controller\ManagementController;

return [
    // Management write operations (authentication checked in controller)
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

    // Management read
    'passkeys_manage_list' => [
        'path' => '/passkeys/manage/list',
        'target' => ManagementController::class . '::listAction',
        'methods' => ['GET'],
    ],

    // Admin write operations (admin-level authentication checked in controller)
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
    'passkeys_admin_revoke_all' => [
        'path' => '/passkeys/admin/revoke-all',
        'target' => AdminController::class . '::revokeAllAction',
        'methods' => ['POST'],
    ],

    // Admin read
    'passkeys_admin_list' => [
        'path' => '/passkeys/admin/list',
        'target' => AdminController::class . '::listAction',
        'methods' => ['GET'],
    ],

    // Enforcement status -- banner display decision
    'passkeys_enforcement_status' => [
        'path' => '/passkeys/enforcement/status',
        'target' => ManagementController::class . '::enforcementStatusAction',
        'methods' => ['GET'],
    ],

    // Admin dashboard -- update group enforcement level
    'passkeys_admin_update_enforcement' => [
        'path' => '/passkeys/admin/update-enforcement',
        'target' => AdminController::class . '::updateEnforcementAction',
        'methods' => ['POST'],
    ],

    // Admin dashboard -- send passkey setup reminder to user
    'passkeys_admin_send_reminder' => [
        'path' => '/passkeys/admin/send-reminder',
        'target' => AdminController::class . '::sendReminderAction',
        'methods' => ['POST'],
    ],

    // Admin dashboard -- clear an active passkey setup nudge
    'passkeys_admin_clear_nudge' => [
        'path' => '/passkeys/admin/clear-nudge',
        'target' => AdminController::class . '::clearNudgeAction',
        'methods' => ['POST'],
    ],
];
