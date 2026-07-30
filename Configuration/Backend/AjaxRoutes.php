<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Controller\AdminController;
use Netresearch\NrPasskeysBe\Controller\ManagementController;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessLifetime;

/**
 * Sudo Mode settings shared by every passkey write route.
 *
 * A passkey is a primary credential: enrolling one is equivalent to setting a
 * password, and revoking one changes how an account can authenticate. Without this
 * option RouteDispatcher::assertSudoMode() returns immediately, so a borrowed
 * backend session alone was enough to enrol an authenticator or strip another
 * user's passkeys. Core gates its own MFA setup route the same way.
 */
$sudoMode = [
    'group' => 'passkeys',
    'lifetime' => AccessLifetime::medium,
];

return [
    // Management write operations (authentication checked in controller)
    'passkeys_manage_registration_options' => [
        'path' => '/passkeys/manage/registration/options',
        'target' => ManagementController::class . '::registrationOptionsAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],
    'passkeys_manage_registration_verify' => [
        'path' => '/passkeys/manage/registration/verify',
        'target' => ManagementController::class . '::registrationVerifyAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],
    'passkeys_manage_rename' => [
        'path' => '/passkeys/manage/rename',
        'target' => ManagementController::class . '::renameAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],
    'passkeys_manage_remove' => [
        'path' => '/passkeys/manage/remove',
        'target' => ManagementController::class . '::removeAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
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
        'sudoMode' => $sudoMode,
    ],
    'passkeys_admin_unlock' => [
        'path' => '/passkeys/admin/unlock',
        'target' => AdminController::class . '::unlockAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],
    'passkeys_admin_revoke_all' => [
        'path' => '/passkeys/admin/revoke-all',
        'target' => AdminController::class . '::revokeAllAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
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
        'sudoMode' => $sudoMode,
    ],

    // Admin dashboard -- send passkey setup reminder to user
    'passkeys_admin_send_reminder' => [
        'path' => '/passkeys/admin/send-reminder',
        'target' => AdminController::class . '::sendReminderAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],

    // Admin dashboard -- clear an active passkey setup nudge
    'passkeys_admin_clear_nudge' => [
        'path' => '/passkeys/admin/clear-nudge',
        'target' => AdminController::class . '::clearNudgeAction',
        'methods' => ['POST'],
        'sudoMode' => $sudoMode,
    ],
];
