<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthn assertion (authentication) ceremony: building request options for the
 * username-first, discoverable, and decoy login variants, resolving the backend
 * user from a discoverable assertion, and verifying the browser's assertion response.
 */
final class AssertionService
{
    public function __construct(
        private readonly ExtensionConfigurationService $configService,
        private readonly ChallengeService $challengeService,
        private readonly CredentialRepository $credentialRepository,
        private readonly LoggerInterface $logger,
        private readonly WebAuthnCeremonyFactory $ceremonyFactory,
    ) {}

    /**
     * Create assertion options for login (Variant A: username-first).
     */
    public function createAssertionOptions(string $username, int $beUserUid): AssertionOptions
    {
        $rpId = $this->configService->getEffectiveRpId();
        $challenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($challenge);

        $credentials = $this->credentialRepository->findByBeUser($beUserUid);
        $allowCredentials = \array_map(
            static fn(Credential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $cred->getCredentialId(),
                transports: $cred->getTransportsArray(),
            ),
            $credentials,
        );

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            timeout: 60000,
        );

        return new AssertionOptions(
            options: $options,
            challengeToken: $challengeToken,
        );
    }

    /**
     * Create assertion options for discoverable login (Variant B: identifierless).
     */
    public function createDiscoverableAssertionOptions(): AssertionOptions
    {
        $rpId = $this->configService->getEffectiveRpId();
        $challenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($challenge);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: [],
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            timeout: 60000,
        );

        return new AssertionOptions(
            options: $options,
            challengeToken: $challengeToken,
        );
    }

    /**
     * Create *decoy* assertion options for an unknown username.
     *
     * Returns options structurally identical to a real user's so the public
     * login-options endpoint cannot be used to enumerate valid backend usernames.
     * The decoy allowCredentials are derived deterministically from the username
     * (HMAC over an HKDF-derived key from the extension encryption key), so repeated
     * requests for the same unknown username yield stable, unguessable credential
     * descriptors. A subsequent assertion against a decoy fails verification exactly
     * as a wrong passkey would, keeping known and unknown users indistinguishable.
     */
    public function createDecoyAssertionOptions(string $username): AssertionOptions
    {
        $rpId = $this->configService->getEffectiveRpId();
        $challenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($challenge);

        $key = $this->configService->getEncryptionKey();
        $derivedKey = \hash_hkdf('sha256', $key, 32, 'nr_passkeys_be_decoy');
        $decoyId = \hash_hmac('sha256', $username, $derivedKey, true);

        $allowCredentials = [
            PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $decoyId,
                transports: [],
            ),
        ];

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            timeout: 60000,
        );

        return new AssertionOptions(
            options: $options,
            challengeToken: $challengeToken,
        );
    }

    /**
     * Resolve the backend user UID from a passkey assertion response.
     *
     * Used for discoverable (usernameless) login where the credential ID
     * in the assertion identifies the user without requiring a username.
     */
    public function findBeUserUidFromAssertion(string $responseJson): ?int
    {
        try {
            $publicKeyCredential = $this->ceremonyFactory->getSerializer()->deserialize(
                $responseJson,
                PublicKeyCredential::class,
                'json',
            );

            if (!$publicKeyCredential instanceof PublicKeyCredential) {
                return null;
            }

            $credential = $this->credentialRepository->findByCredentialId($publicKeyCredential->rawId);
            if ($credential === null || $credential->isRevoked()) {
                return null;
            }

            return $credential->getBeUser();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Verify an assertion response for login.
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyAssertionResponse(
        string $responseJson,
        string $challengeToken,
        int $beUserUid,
    ): VerifiedAssertion {
        $challenge = $this->challengeService->verifyChallengeToken($challengeToken);
        $rpId = $this->configService->getEffectiveRpId();

        // Deserialize the browser response
        $publicKeyCredential = $this->ceremonyFactory->getSerializer()->deserialize(
            $responseJson,
            PublicKeyCredential::class,
            'json',
        );

        if (!$publicKeyCredential instanceof PublicKeyCredential) {
            throw new RuntimeException('Failed to deserialize assertion response', 1700000030);
        }

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Expected assertion response', 1700000031);
        }

        // Find the credential by its ID
        $credentialId = $publicKeyCredential->rawId;
        $credential = $this->credentialRepository->findByCredentialId($credentialId);

        if ($credential === null) {
            $this->logger->warning('Assertion with unknown credential ID', [
                'be_user_uid' => $beUserUid,
            ]);
            throw new RuntimeException('Unknown credential', 1700000032);
        }

        if ($credential->isRevoked()) {
            throw new RuntimeException('Credential has been revoked', 1700000033);
        }

        if ($credential->getBeUser() !== $beUserUid) {
            $this->logger->warning('Credential does not belong to the claimed user', [
                'be_user_uid' => $beUserUid,
                'credential_be_user' => $credential->getBeUser(),
            ]);
            throw new RuntimeException('Credential mismatch', 1700000034);
        }

        $storedSource = $this->credentialToSource($credential);

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
        );

        $factory = $this->ceremonyFactory->createCeremonyFactory();
        $ceremonyManager = $factory->requestCeremony();
        $validator = AuthenticatorAssertionResponseValidator::create($ceremonyManager);

        try {
            $updatedSource = $validator->check(
                credentialRecord: $storedSource,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: $requestOptions,
                host: $rpId,
                userHandle: $credential->getUserHandle() !== '' ? $credential->getUserHandle() : null,
            );
        } catch (Throwable $e) {
            $this->logger->error('Passkey assertion verification failed', [
                'be_user_uid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Assertion verification failed: ' . $e->getMessage(),
                1700000035,
                $e,
            );
        }

        $this->credentialRepository->updateSignCount($credential->getUid(), $updatedSource->counter);
        $this->credentialRepository->updateLastUsed($credential->getUid());

        $this->logger->info('Passkey login successful', [
            'be_user_uid' => $beUserUid,
            'credential_uid' => $credential->getUid(),
        ]);

        return new VerifiedAssertion(
            credential: $credential,
            source: $updatedSource,
        );
    }

    /**
     * Serialize PublicKeyCredentialRequestOptions to JSON for the browser.
     */
    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->ceremonyFactory->getSerializer()->serialize($options, 'json');
    }

    private function credentialToSource(Credential $credential): CredentialRecord
    {
        $aaguid = $credential->getAaguid() !== ''
            ? Uuid::fromString($credential->getAaguid())
            : Uuid::v4();

        return CredentialRecord::create(
            publicKeyCredentialId: $credential->getCredentialId(),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $credential->getTransportsArray(),
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: $aaguid,
            credentialPublicKey: $credential->getPublicKeyCose(),
            userHandle: $credential->getUserHandle(),
            counter: $credential->getSignCount(),
        );
    }
}
