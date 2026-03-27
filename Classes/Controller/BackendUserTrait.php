<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Domain\Dto\AuthenticatedUser;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Extracts the authenticated backend user from $GLOBALS['BE_USER'].
 *
 * Provides getAuthenticatedUser() for any authenticated user and
 * requireAdmin() which additionally enforces admin privileges.
 */
trait BackendUserTrait
{
    /**
     * Resolve the currently authenticated backend user.
     *
     * Returns null when no valid backend session exists.
     */
    private function getAuthenticatedUser(): ?AuthenticatedUser
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        $userData = $backendUser->user;
        if (!\is_array($userData)) {
            return null;
        }

        $rawUid = $userData['uid'] ?? null;
        if (!\is_numeric($rawUid)) {
            return null;
        }

        $rawUsername = $userData['username'] ?? '';
        $rawRealName = $userData['realName'] ?? '';

        return new AuthenticatedUser(
            uid: (int) $rawUid,
            username: \is_string($rawUsername) ? $rawUsername : '',
            realName: \is_string($rawRealName) ? $rawRealName : '',
            isAdmin: $backendUser->isAdmin(),
        );
    }

    /**
     * Resolve the currently authenticated backend user, requiring admin privileges.
     *
     * Returns null when no valid admin session exists.
     */
    private function requireAdmin(): ?AuthenticatedUser
    {
        $user = $this->getAuthenticatedUser();
        if ($user === null || !$user->isAdmin) {
            return null;
        }

        return $user;
    }
}
