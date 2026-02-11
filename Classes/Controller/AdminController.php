<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

class AdminController
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
        $admin = $this->requireAdmin($request);
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
            static fn($cred) => [
                'uid' => $cred->getUid(),
                'label' => $cred->getLabel(),
                'createdAt' => $cred->getCreatedAt(),
                'lastUsedAt' => $cred->getLastUsedAt(),
                'isRevoked' => $cred->isRevoked(),
                'revokedAt' => $cred->getRevokedAt(),
                'revokedBy' => $cred->getRevokedBy(),
            ],
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
        $admin = $this->requireAdmin($request);
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

        $rawAdminUid = $admin['uid'] ?? null;
        $adminUid = \is_numeric($rawAdminUid) ? (int) $rawAdminUid : 0;
        $this->credentialRepository->revoke($credentialUid, $adminUid);

        $this->logger->info('Admin revoked passkey', [
            'admin_uid' => $adminUid,
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
        $admin = $this->requireAdmin($request);
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
            'admin_uid' => $admin['uid'],
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
        $admin = $this->requireAdmin($request);
        if ($admin === null) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $body = $this->getJsonBody($request);
        $rawUid = $body['beUserUid'] ?? null;
        $beUserUid = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($beUserUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $rawAdminUid = $admin['uid'] ?? null;
        $adminUid = \is_numeric($rawAdminUid) ? (int) $rawAdminUid : 0;
        $credentials = $this->credentialRepository->findAllByBeUser($beUserUid);
        $revokedCount = 0;

        foreach ($credentials as $credential) {
            if (!$credential->isRevoked()) {
                $this->credentialRepository->revoke($credential->getUid(), $adminUid);
                ++$revokedCount;
            }
        }

        $this->logger->info('Admin revoked all passkeys', [
            'admin_uid' => $adminUid,
            'be_user_uid' => $beUserUid,
            'revoked_count' => $revokedCount,
        ]);

        return new JsonResponse(['status' => 'ok', 'revokedCount' => $revokedCount]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireAdmin(ServerRequestInterface $request): ?array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        if (!isset($backendUser->user['uid'])) {
            return null;
        }

        if (!$backendUser->isAdmin()) {
            return null;
        }

        /** @var array<string, mixed> $user */
        $user = $backendUser->user;

        return $user;
    }

}
