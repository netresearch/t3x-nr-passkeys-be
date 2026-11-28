<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\EventListener;

use Netresearch\NrPasskeysBe\Service\SudoModeTokenService;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeVerifyEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

use function is_array;
use function is_numeric;

/**
 * Accepts a passkey confirmation in TYPO3's sudo-mode dialog.
 *
 * The browser submits the token from SudoModeController through the core
 * form, in the password field. Anything that is not such a token is left
 * alone and goes on to the core's password check; the install tool password
 * mode is never touched.
 */
#[AsEventListener(identifier: 'nr-passkeys-be/verify-sudo-mode-with-passkey')]
final readonly class VerifySudoModeWithPasskey
{
    public function __construct(private SudoModeTokenService $tokenService) {}

    public function __invoke(SudoModeVerifyEvent $event): void
    {
        if ($event->isVerified() || $event->isUseInstallToolPassword()) {
            return;
        }

        $token = $event->getPassword();

        if (!$this->tokenService->isToken($token)) {
            return;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication || !is_array($backendUser->user)) {
            return;
        }

        $uid = $backendUser->user['uid'] ?? 0;

        if ($this->tokenService->verify(
            $token,
            is_numeric($uid) ? (int) $uid : 0,
            $backendUser
                ->getSession()
                ->getIdentifier(),
            $event->getClaim()->id,
        )) {
            $event->setVerified(true);
        }
    }
}
