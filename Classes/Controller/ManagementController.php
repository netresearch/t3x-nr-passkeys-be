<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

class ManagementController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly WebAuthnService $webAuthnService,
        private readonly CredentialRepository $credentialRepository,
        private readonly ExtensionConfigurationService $configService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate registration options for the current user.
     *
     * POST /passkeys/manage/registration/options
     */
    public function registrationOptionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser($request);
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $result = $this->webAuthnService->createRegistrationOptions(
                beUserUid: (int) $user['uid'],
                username: (string) $user['username'],
                displayName: (string) ($user['realName'] ?: $user['username']),
            );

            $optionsJson = $this->webAuthnService->serializeCreationOptions($result['options']);

            return new JsonResponse([
                'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $result['challengeToken'],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate registration options', [
                'be_user_uid' => $user['uid'],
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to generate options'], 500);
        }
    }

    /**
     * Verify registration response and store credential.
     *
     * POST /passkeys/manage/registration/verify
     * Body: { "credential": {...}, "challengeToken": "...", "label": "..." }
     */
    public function registrationVerifyAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser($request);
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $body = $this->getJsonBody($request);
        $credentialJson = isset($body['credential']) ? \json_encode($body['credential'], JSON_THROW_ON_ERROR) : '';
        $challengeToken = (string) ($body['challengeToken'] ?? '');
        $label = (string) ($body['label'] ?? 'Passkey');

        if ($credentialJson === '' || $challengeToken === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Sanitize label
        $label = \mb_substr(\trim($label), 0, 128);
        if ($label === '') {
            $label = 'Passkey';
        }

        try {
            $source = $this->webAuthnService->verifyRegistrationResponse(
                responseJson: $credentialJson,
                challengeToken: $challengeToken,
                beUserUid: (int) $user['uid'],
                username: (string) $user['username'],
                displayName: (string) ($user['realName'] ?: $user['username']),
            );

            $credential = $this->webAuthnService->storeCredential(
                source: $source,
                beUserUid: (int) $user['uid'],
                label: $label,
            );

            $this->logger->info('Passkey registered', [
                'be_user_uid' => $user['uid'],
                'credential_uid' => $credential->getUid(),
                'label' => $label,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'credential' => $credential->toPublicArray(),
            ]);
        } catch (RuntimeException $e) {
            $this->logger->error('Passkey registration failed', [
                'be_user_uid' => $user['uid'],
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Registration failed'], 400);
        }
    }

    /**
     * List all passkeys for the current user.
     *
     * GET /passkeys/manage/list
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser($request);
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $credentials = $this->credentialRepository->findByBeUser((int) $user['uid']);
        $list = \array_map(
            static fn($cred) => $cred->toPublicArray(),
            $credentials,
        );

        return new JsonResponse([
            'credentials' => $list,
            'count' => \count($list),
            'enforcementEnabled' => $this->configService->getConfiguration()->isDisablePasswordLogin(),
        ]);
    }

    /**
     * Rename a passkey label.
     *
     * POST /passkeys/manage/rename
     * Body: { "uid": 123, "label": "New Name" }
     */
    public function renameAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser($request);
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $body = $this->getJsonBody($request);
        $credentialUid = (int) ($body['uid'] ?? 0);
        $label = (string) ($body['label'] ?? '');

        if ($credentialUid === 0 || $label === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $label = \mb_substr(\trim($label), 0, 128);

        // Verify ownership
        $credentials = $this->credentialRepository->findByBeUser((int) $user['uid']);
        $owned = false;
        foreach ($credentials as $cred) {
            if ($cred->getUid() === $credentialUid) {
                $owned = true;
                break;
            }
        }

        if (!$owned) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        $this->credentialRepository->updateLabel($credentialUid, $label);

        $this->logger->info('Passkey renamed', [
            'be_user_uid' => $user['uid'],
            'credential_uid' => $credentialUid,
            'new_label' => $label,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Remove a passkey.
     *
     * POST /passkeys/manage/remove
     * Body: { "uid": 123 }
     */
    public function removeAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser($request);
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $body = $this->getJsonBody($request);
        $credentialUid = (int) ($body['uid'] ?? 0);

        if ($credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing credential uid'], 400);
        }

        $beUserUid = (int) $user['uid'];

        // Verify ownership
        $credentials = $this->credentialRepository->findByBeUser($beUserUid);
        $owned = false;
        foreach ($credentials as $cred) {
            if ($cred->getUid() === $credentialUid) {
                $owned = true;
                break;
            }
        }

        if (!$owned) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        // Block removal of last passkey when enforcement is enabled
        $count = $this->credentialRepository->countByBeUser($beUserUid);
        if ($count <= 1 && $this->configService->getConfiguration()->isDisablePasswordLogin()) {
            return new JsonResponse([
                'error' => 'Cannot remove your last passkey when password login is disabled',
            ], 409);
        }

        $this->credentialRepository->delete($credentialUid);

        $this->logger->info('Passkey removed', [
            'be_user_uid' => $beUserUid,
            'credential_uid' => $credentialUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAuthenticatedUser(ServerRequestInterface $request): ?array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        if (!isset($backendUser->user['uid'])) {
            return null;
        }

        return $backendUser->user;
    }

}
