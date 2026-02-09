<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

class AdminController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly CredentialRepository $credentialRepository,
        private readonly RateLimiterService $rateLimiterService,
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
        $beUserUid = (int) ($queryParams['beUserUid'] ?? 0);

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
        $beUserUid = (int) ($body['beUserUid'] ?? 0);
        $credentialUid = (int) ($body['credentialUid'] ?? 0);

        if ($beUserUid === 0 || $credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Verify the credential belongs to the specified user
        $credential = $this->credentialRepository->findByUidAndBeUser($credentialUid, $beUserUid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found for this user'], 404);
        }

        $adminUid = (int) $admin['uid'];
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
        $beUserUid = (int) ($body['beUserUid'] ?? 0);
        $username = (string) ($body['username'] ?? '');

        if ($beUserUid === 0 || $username === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
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

        return $backendUser->user;
    }

}
