<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Domain\Dto\AuthenticatedUser;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
