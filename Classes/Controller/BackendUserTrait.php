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

    /**
     * Whether the current session is a switch-user (impersonation) session.
     *
     * In switch-user mode $GLOBALS['BE_USER']->user is the impersonated user, so a
     * passkey registration would bind the acting admin's authenticator to the
     * impersonated account as a permanent, password-independent credential —
     * bypassing the system-maintainer boundary isManagementAllowedFor() enforces
     * on the admin endpoints. Core refuses MFA setup in this mode for the same
     * reason (MfaSetupController).
     */
    private function isSwitchUserMode(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        return $backendUser->getOriginalUserIdWhenInSwitchUserMode() !== null;
    }

    /**
     * Whether the current admin may manage the given target backend user's passkeys.
     *
     * Mirrors the system-maintainer boundary enforced in the FormEngine UI
     * (PasskeyInfoElement): only a system maintainer may manage another system
     * maintainer's security settings. Any non-maintainer target is allowed.
     */
    private function isManagementAllowedFor(int $targetUid): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? $typo3Conf['SYS'] ?? null : null;
        $systemMaintainers = \is_array($sysConf) ? $sysConf['systemMaintainers'] ?? [] : [];

        if (!\is_array($systemMaintainers) || $systemMaintainers === []) {
            return true;
        }

        $systemMaintainerIds = [];

        foreach ($systemMaintainers as $maintainer) {
            if (\is_numeric($maintainer)) {
                $systemMaintainerIds[] = (int) $maintainer;
            }
        }

        if (!\in_array($targetUid, $systemMaintainerIds, true)) {
            return true;
        }

        return $backendUser->isSystemMaintainer();
    }
}
