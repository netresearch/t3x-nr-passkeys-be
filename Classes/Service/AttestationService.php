<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\RegistrationOptions;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn attestation (registration) ceremony: building creation options,
 * verifying the browser's attestation response, and persisting the credential.
 */
final readonly class AttestationService
{
    private const ALGORITHM_MAP = [
        'ES256' => -7,
        'ES384' => -35,
        'ES512' => -36,
        'RS256' => -257,
    ];

    public function __construct(
        private ExtensionConfigurationService $configService,
        private ChallengeService $challengeService,
        private CredentialRepository $credentialRepository,
        private LoggerInterface $logger,
        private WebAuthnCeremonyFactory $ceremonyFactory,
    ) {}

    /**
     * Create registration options for a backend user.
     */
    public function createRegistrationOptions(int $beUserUid, string $username, string $displayName): RegistrationOptions
    {
        $rpId = $this->configService->getEffectiveRpId();
        $rpName = $this->configService->getConfiguration()->getRpName();

        $rp = PublicKeyCredentialRpEntity::create(
            name: $rpName,
            id: $rpId,
        );

        $userHandle = $this->createUserHandle($beUserUid);

        $user = PublicKeyCredentialUserEntity::create(
            name: $username,
            id: $userHandle,
            displayName: $displayName,
        );

        $challenge = $this->challengeService->generateChallenge();
        $challengeToken = $this->challengeService->createChallengeToken($challenge);

        $existingCredentials = $this->credentialRepository->findByBeUser($beUserUid);
        $excludeCredentials = \array_map(
            static fn(Credential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $cred->getCredentialId(),
                transports: $cred->getTransportsArray(),
            ),
            $existingCredentials,
        );

        $authenticatorSelection = AuthenticatorSelectionCriteria::create(
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $user,
            challenge: $challenge,
            pubKeyCredParams: $this->getPublicKeyCredentialParameters(),
            authenticatorSelection: $authenticatorSelection,
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );

        return new RegistrationOptions(
            options: $options,
            challengeToken: $challengeToken,
        );
    }

    /**
     * Verify a registration response from the browser.
     *
     * The $responseJson is the JSON-serialized PublicKeyCredential from the browser,
     * already base64url-encoded as per the WebAuthn spec.
     *
     * @throws RuntimeException on verification failure
     */
    public function verifyRegistrationResponse(
        string $responseJson,
        string $challengeToken,
        int $beUserUid,
        string $username,
        string $displayName,
    ): CredentialRecord {
        $challenge = $this->challengeService->verifyChallengeToken($challengeToken);

        $rpId = $this->configService->getEffectiveRpId();
        $rpName = $this->configService->getConfiguration()->getRpName();
        $userHandle = $this->createUserHandle($beUserUid);

        $rp = PublicKeyCredentialRpEntity::create(name: $rpName, id: $rpId);
        $user = PublicKeyCredentialUserEntity::create(
            name: $username,
            id: $userHandle,
            displayName: $displayName,
        );

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $user,
            challenge: $challenge,
            pubKeyCredParams: $this->getPublicKeyCredentialParameters(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );

        // Deserialize the browser response
        $publicKeyCredential = $this->ceremonyFactory->getSerializer()->deserialize(
            $responseJson,
            PublicKeyCredential::class,
            'json',
        );

        if (!$publicKeyCredential instanceof PublicKeyCredential) {
            throw new RuntimeException('Failed to deserialize credential response', 1700000020);
        }

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Expected attestation response', 1700000021);
        }

        $factory = $this->ceremonyFactory->createCeremonyFactory();
        $ceremonyManager = $factory->creationCeremony();
        $validator = AuthenticatorAttestationResponseValidator::create($ceremonyManager);

        try {
            $source = $validator->check(
                authenticatorAttestationResponse: $response,
                publicKeyCredentialCreationOptions: $creationOptions,
                host: $rpId,
            );
        } catch (Throwable $e) {
            $this->logger->error('Passkey registration verification failed', [
                'be_user_uid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Registration verification failed: ' . $e->getMessage(),
                1700000022,
                $e,
            );
        }

        $this->logger->info('Passkey registered successfully', [
            'be_user_uid' => $beUserUid,
            'username' => $username,
        ]);

        return $source;
    }

    /**
     * Store a verified registration result as a Credential.
     */
    public function storeCredential(
        CredentialRecord $source,
        int $beUserUid,
        string $label,
    ): Credential {
        $credential = new Credential(
            beUser: $beUserUid,
            credentialId: $source->publicKeyCredentialId,
            publicKeyCose: $source->credentialPublicKey,
            signCount: $source->counter,
            userHandle: $source->userHandle,
            aaguid: $source->aaguid->toString(),
            transports: \json_encode($source->transports, JSON_THROW_ON_ERROR),
            label: $label,
        );

        $uid = $this->credentialRepository->save($credential);
        $credential->setUid($uid);

        return $credential;
    }

    /**
     * Serialize PublicKeyCredentialCreationOptions to JSON for the browser.
     */
    public function serializeCreationOptions(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->ceremonyFactory->getSerializer()->serialize($options, 'json');
    }

    /**
     * @return list<PublicKeyCredentialParameters>
     */
    private function getPublicKeyCredentialParameters(): array
    {
        $algorithms = $this->configService->getConfiguration()->getAllowedAlgorithmsList();
        $params = [];

        foreach ($algorithms as $algo) {
            $algoId = self::ALGORITHM_MAP[\strtoupper(\trim($algo))] ?? null;
            if ($algoId !== null) {
                $params[] = PublicKeyCredentialParameters::createPk($algoId);
            }
        }

        return $params;
    }

    private function createUserHandle(int $beUserUid): string
    {
        $key = $this->configService->getEncryptionKey();
        $derivedKey = \hash_hkdf('sha256', $key, 32, 'nr_passkeys_be_user_handle');

        return \hash_hmac('sha256', (string) $beUserUid, $derivedKey, true);
    }
}
