<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Domain\Dto\AuthenticatedUser;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

final class AdminController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly CredentialRepository $credentialRepository,
        private readonly RateLimiterService $rateLimiterService,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List all passkeys for a specific backend user.
     *
     * GET /passkeys/admin/list?beUserUid=123
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $queryParams = $request->getQueryParams();
        $rawUid = $queryParams['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($beUserUid === 0) {
            return new JsonResponse(['error' => 'Missing beUserUid parameter'], 400);
        }

        $credentials = $this->credentialRepository->findAllByBeUser($beUserUid);
        $list = \array_map(
            static fn($cred) => $cred->toAdminCredentialInfo(),
            $credentials,
        );

        return new JsonResponse([
            'beUserUid' => $beUserUid,
            'credentials' => $list,
            'count' => \count($list),
        ]);
    }

    /**
     * Remove/revoke a specific passkey for a backend user.
     *
     * POST /passkeys/admin/remove
     * Body: { "beUserUid": 123, "credentialUid": 456 }
     */
    public function removeAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        $rawCredUid = $body['credentialUid'] ?? null;
        $credentialUid = \is_numeric($rawCredUid) ? (int) $rawCredUid : 0;

        if ($beUserUid === 0 || $credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Verify the credential belongs to the specified user
        $credential = $this->credentialRepository->findByUidAndBeUser($credentialUid, $beUserUid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found for this user'], 404);
        }

        $this->credentialRepository->revoke($credentialUid, $admin->uid);

        $this->logger->info('Admin revoked passkey', [
            'admin_uid' => $admin->uid,
            'be_user_uid' => $beUserUid,
            'credential_uid' => $credentialUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Unlock a locked-out backend user.
     *
     * POST /passkeys/admin/unlock
     * Body: { "beUserUid": 123 }
     */
    public function unlockAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;
        $rawUsername = $body['username'] ?? null;
        $username = \is_string($rawUsername) ? $rawUsername : '';

        if ($beUserUid === 0 || $username === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Validate that beUserUid matches the given username to ensure audit log integrity
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $row = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || $row['username'] !== $username) {
            return new JsonResponse(['error' => 'User not found or username mismatch'], 404);
        }

        $this->rateLimiterService->resetLockout($username);

        $this->logger->info('Admin unlocked user account', [
            'admin_uid' => $admin->uid,
            'be_user_uid' => $beUserUid,
            'username' => $username,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Revoke all active passkeys for a backend user.
     *
     * POST /passkeys/admin/revoke-all
     * Body: { "beUserUid": 123 }
     */
    public function revokeAllAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($beUserUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $credentials = $this->credentialRepository->findAllByBeUser($beUserUid);
        $revokedCount = 0;

        foreach ($credentials as $credential) {
            if (!$credential->isRevoked()) {
                $this->credentialRepository->revoke($credential->getUid(), $admin->uid);
                ++$revokedCount;
            }
        }

        $this->logger->info('Admin revoked all passkeys', [
            'admin_uid' => $admin->uid,
            'be_user_uid' => $beUserUid,
            'revoked_count' => $revokedCount,
        ]);

        return new JsonResponse(['status' => 'ok', 'revokedCount' => $revokedCount]);
    }

    /**
     * Update the passkey enforcement level for a backend user group.
     *
     * POST /passkeys/admin/update-enforcement
     * Body: { "groupUid": 5, "enforcement": "encourage" }
     */
    public function updateEnforcementAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);

        $rawGroupUid = $body['groupUid'] ?? null;
        $groupUid = \is_numeric($rawGroupUid) ? (int) $rawGroupUid : 0;

        $rawEnforcement = $body['enforcement'] ?? null;
        $enforcement = \is_string($rawEnforcement) ? $rawEnforcement : '';

        if ($groupUid === 0 || $enforcement === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $level = EnforcementLevel::tryFrom($enforcement);
        if ($level === null) {
            return new JsonResponse(['error' => 'Invalid enforcement level'], 400);
        }

        // Verify the group exists
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($groupUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return new JsonResponse(['error' => 'Group not found'], 404);
        }

        $connection = $this->connectionPool->getConnectionForTable('be_groups');
        $connection->update(
            'be_groups',
            ['passkey_enforcement' => $level->value],
            ['uid' => $groupUid],
        );

        $this->logger->info('Admin updated group enforcement', [
            'admin_uid' => $admin->uid,
            'group_uid' => $groupUid,
            'enforcement' => $level->value,
        ]);

        return new JsonResponse(['status' => 'ok', 'enforcement' => $level->value]);
    }

    /**
     * Send a passkey setup reminder to a backend user.
     *
     * Sets the be_users.passkey_nudge_until field to a future timestamp so
     * the encourage banner system picks up the nudge.
     *
     * POST /passkeys/admin/send-reminder
     * Body: { "beUserUid": 42 }
     */
    public function sendReminderAction(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->requireAdmin();
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);

        $rawUid = $body['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($beUserUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Verify the user exists and is active
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return new JsonResponse(['error' => 'User not found'], 404);
        }

        // Set passkey_nudge_until to 14 days from now
        $nudgeUntil = time() + (14 * 86_400);

        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $connection->update(
            'be_users',
            ['passkey_nudge_until' => $nudgeUntil],
            ['uid' => $beUserUid],
        );

        $usernameValue = $row['username'] ?? '';
        $username = \is_string($usernameValue) ? $usernameValue : '';

        $this->logger->info('Admin sent passkey reminder', [
            'admin_uid' => $admin->uid,
            'be_user_uid' => $beUserUid,
            'username' => $username,
            'nudge_until' => $nudgeUntil,
        ]);

        return new JsonResponse(['status' => 'ok', 'nudgeUntil' => $nudgeUntil]);
    }

    private function requireAdmin(): ?AuthenticatedUser
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

        if (!$backendUser->isAdmin()) {
            return null;
        }

        $rawUsername = $userData['username'] ?? '';
        $rawRealName = $userData['realName'] ?? '';

        return new AuthenticatedUser(
            uid: (int) $rawUid,
            username: \is_string($rawUsername) ? $rawUsername : '',
            realName: \is_string($rawRealName) ? $rawRealName : '',
            isAdmin: true,
        );
    }

}
