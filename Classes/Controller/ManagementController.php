<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use Netresearch\NrPasskeysBe\Utility\TypeCastTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

final class ManagementController
{
    use BackendUserTrait;
    use JsonBodyTrait;
    use TypeCastTrait;

    public function __construct(
        private readonly WebAuthnService $webAuthnService,
        private readonly CredentialRepository $credentialRepository,
        private readonly ExtensionConfigurationService $configService,
        private readonly EnforcementService $enforcementService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate registration options for the current user.
     *
     * POST /passkeys/manage/registration/options
     */
    public function registrationOptionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        if ($this->isSwitchUserMode()) {
            return $this->denySwitchUserMode('registration_options', $user->uid);
        }

        try {
            $result = $this->webAuthnService->createRegistrationOptions(
                beUserUid: $user->uid,
                username: $user->username,
                displayName: $user->realName !== '' ? $user->realName : $user->username,
            );

            $optionsJson = $this->webAuthnService->serializeCreationOptions($result->options);

            return new JsonResponse([
                'options' => \json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR),
                'challengeToken' => $result->challengeToken,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate registration options', [
                'be_user_uid' => $user->uid,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to generate registration options'], 500);
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
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        if ($this->isSwitchUserMode()) {
            return $this->denySwitchUserMode('registration_verify', $user->uid);
        }

        $body = $this->getJsonBody($request);
        $credentialJson = isset($body['credential']) ? \json_encode($body['credential'], JSON_THROW_ON_ERROR) : '';
        $rawToken = $body['challengeToken'] ?? '';
        $challengeToken = \is_string($rawToken) ? $rawToken : '';
        $rawLabel = $body['label'] ?? 'Passkey';
        $label = \is_string($rawLabel) ? $rawLabel : 'Passkey';

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
                beUserUid: $user->uid,
                username: $user->username,
                displayName: $user->realName !== '' ? $user->realName : $user->username,
            );

            $credential = $this->webAuthnService->storeCredential(
                source: $source,
                beUserUid: $user->uid,
                label: $label,
            );

            $this->logger->info('Passkey registered', [
                'be_user_uid' => $user->uid,
                'credential_uid' => $credential->getUid(),
                'label' => $label,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'credential' => $credential->toCredentialInfo(),
            ]);
        } catch (RuntimeException $e) {
            $this->logger->error('Passkey registration failed', [
                'be_user_uid' => $user->uid,
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
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $credentials = $this->credentialRepository->findByBeUser($user->uid);
        $list = \array_map(
            static fn($cred) => $cred->toCredentialInfo(),
            $credentials,
        );

        return new JsonResponse([
            'credentials' => $list,
            'count' => \count($list),
            'enforcementEnabled' => $this->configService->getConfiguration()->isDisablePasswordLogin(),
        ]);
    }

    /**
     * Return the enforcement status for the current user.
     *
     * GET /passkeys/enforcement/status
     */
    public function enforcementStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $userRow = $backendUser->user;
        if (!\is_array($userRow)) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        /** @var array<string, mixed> $userRow */
        $status = $this->enforcementService->getStatus($userRow);

        // Check if an admin-sent nudge is active (passkey_nudge_until in the future).
        // A nudge only triggers the banner if the user has no passkeys yet —
        // once they register a passkey, the nudge becomes irrelevant.
        $nudgeUntil = $userRow['passkey_nudge_until'] ?? 0;
        $hasActiveNudge = !$status->hasPasskeys
            && \is_numeric($nudgeUntil)
            && (int) $nudgeUntil > \time();

        $requiresBanner = ($status->level->requiresBanner() && !$status->hasPasskeys) || $hasActiveNudge;

        return new JsonResponse([
            'level' => $status->level->value,
            'hasPasskeys' => $status->hasPasskeys,
            'requiresBanner' => $requiresBanner,
            'gracePeriodRemainingDays' => $status->gracePeriodRemainingDays(),
            'nudgeUntil' => $hasActiveNudge ? (int) $nudgeUntil : 0,
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
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        if ($this->isSwitchUserMode()) {
            return $this->denySwitchUserMode('rename', $user->uid);
        }

        $body = $this->getJsonBody($request);
        $credentialUid = self::intVal($body['uid'] ?? null);
        $rawLabel = $body['label'] ?? null;
        $label = \is_string($rawLabel) ? $rawLabel : '';

        if ($credentialUid === 0 || $label === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $label = \mb_substr(\trim($label), 0, 128);
        if ($label === '') {
            $label = 'Passkey';
        }

        // Verify ownership
        $credential = $this->credentialRepository->findByUidAndBeUser($credentialUid, $user->uid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        $this->credentialRepository->updateLabel($credentialUid, $label);

        $this->logger->info('Passkey renamed', [
            'be_user_uid' => $user->uid,
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
        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        if ($this->isSwitchUserMode()) {
            return $this->denySwitchUserMode('remove', $user->uid);
        }

        $body = $this->getJsonBody($request);
        $credentialUid = self::intVal($body['uid'] ?? null);

        if ($credentialUid === 0) {
            return new JsonResponse(['error' => 'Missing credential uid'], 400);
        }

        // Verify ownership
        $credential = $this->credentialRepository->findByUidAndBeUser($credentialUid, $user->uid);
        if ($credential === null) {
            return new JsonResponse(['error' => 'Credential not found'], 404);
        }

        // Block removal of last passkey when enforcement is enabled
        $count = $this->credentialRepository->countByBeUser($user->uid);
        if ($count <= 1 && $this->configService->getConfiguration()->isDisablePasswordLogin()) {
            return new JsonResponse([
                'error' => 'Cannot remove your last passkey when password login is disabled',
            ], 409);
        }

        $this->credentialRepository->delete($credentialUid);

        $this->logger->info('Passkey removed', [
            'be_user_uid' => $user->uid,
            'credential_uid' => $credentialUid,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Refuse a passkey write issued from a switch-user (impersonation) session.
     *
     * $uid is the impersonated user — the account the write would have targeted.
     */
    private function denySwitchUserMode(string $operation, int $uid): ResponseInterface
    {
        $this->logger->warning('Passkey management blocked in switch-user mode', [
            'operation' => $operation,
            'be_user_uid' => $uid,
        ]);

        return new JsonResponse(
            ['error' => 'Passkeys cannot be managed while impersonating another user'],
            403,
        );
    }
}
