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
final readonly class AssertionService
{
    /**
     * Realistic credential-ID byte lengths. A single fixed length made every decoy
     * recognisable: real authenticators emit 16 or 20 bytes for platform credentials
     * and commonly 32 or 64 for security keys.
     *
     * @var list<int>
     */
    private const DECOY_ID_LENGTHS = [16, 20, 32, 64];

    /**
     * Transport sets browsers actually report, plus the empty set real credentials
     * registered without transport information carry.
     *
     * @var list<list<string>>
     */
    private const DECOY_TRANSPORT_SETS = [['internal'], ['internal', 'hybrid'], ['usb'], ['usb', 'nfc'], ['hybrid'], []];

    public function __construct(
        private ExtensionConfigurationService $configService,
        private ChallengeService $challengeService,
        private CredentialRepository $credentialRepository,
        private LoggerInterface $logger,
        private WebAuthnCeremonyFactory $ceremonyFactory,
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
        $allowCredentials = \array_values(
            \array_map(
                static fn(Credential $cred): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    id: $cred->getCredentialId(),
                    transports: $cred->getTransportsArray(),
                ),
                $credentials,
            ),
        );

        // An existing user who has not registered a passkey yet would otherwise
        // answer with an empty allowCredentials — a reliable "this account exists"
        // signal during rollout, since no unknown username ever produces one. Serve
        // the same decoys an unknown username gets.
        if ($allowCredentials === []) {
            $allowCredentials = $this->buildDecoyDescriptors($username);
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            timeout: 60000,
        );

        return new AssertionOptions(options: $options, challengeToken: $challengeToken);
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

        return new AssertionOptions(options: $options, challengeToken: $challengeToken);
    }

    /**
     * Create *decoy* assertion options for an unknown username.
     *
     * Returns options structurally indistinguishable from a real user's so the public
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
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $this->buildDecoyDescriptors($username),
            userVerification: $this->configService->getConfiguration()->getUserVerification(),
            timeout: 60000,
        );

        return new AssertionOptions(options: $options, challengeToken: $challengeToken);
    }

    /**
     * Build the decoy credential descriptors for a username.
     *
     * Every property an observer can see is varied over the range real responses
     * span — how many descriptors, each id's length, and the transports — and all of
     * it is derived deterministically from HMAC(username) under a key derived from
     * the extension encryption key. Stable across requests, unguessable without the
     * key, and no longer a fingerprint: the previous version always returned exactly
     * one 32-byte id with no transports, which no real response looks like.
     *
     * The selectors and the id come from separate HMACs on purpose — see the comment
     * at the derivation. Nothing an observer receives may let them recompute how that
     * response was shaped, or the decoy identifies itself.
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function buildDecoyDescriptors(string $username): array
    {
        $derivedKey = \hash_hkdf('sha256', $this->configService->getEncryptionKey(), 32, 'nr_passkeys_be_decoy');
        $seed = \hash_hmac('sha256', $username . '|count', $derivedKey, true);
        $count = 1 + \ord($seed[0]) % 3;
        $descriptors = [];

        for ($index = 0; $index < $count; ++$index) {
            // Two independent derivations, and they must stay independent. The
            // selectors decide how long the id is and which transports it claims; the
            // id is what the caller actually receives. Taking both from one HMAC —
            // publishing substr($material, 0, $length) after choosing $length from
            // $material[0] — made every decoy verifiable from its own bytes: the
            // caller could recompute DECOY_ID_LENGTHS[ord($id[0]) % n] and see it
            // match, which no real credential does except by chance. Any response
            // failing that check was then certainly a real, enrolled account, which is
            // precisely the oracle these decoys exist to close.
            $selectors = \hash_hmac('sha256', $username . '|' . $index . '|selectors', $derivedKey, true);
            $length = self::DECOY_ID_LENGTHS[\ord($selectors[0]) % \count(self::DECOY_ID_LENGTHS)];
            $transports = self::DECOY_TRANSPORT_SETS[\ord($selectors[1]) % \count(self::DECOY_TRANSPORT_SETS)];
            $descriptors[] = PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $this->deriveDecoyId($derivedKey, $username . '|' . $index . '|id', $length),
                transports: $transports,
            );
        }

        return $descriptors;
    }

    /**
     * Derive a decoy credential id of the requested length.
     *
     * sha256 yields 32 bytes, so a 64-byte id needs a second block — and every block
     * is its own keyed HMAC. Stretching by hashing the first block instead would make
     * the tail computable from the head the caller already has, which is the same
     * self-identifying defect one level down: `substr($id, 32) === sha256(substr($id, 0, 32) . '|1')`
     * holds for every such decoy and for no real credential. Without the key, no byte
     * of the result predicts any other.
     */
    private function deriveDecoyId(string $derivedKey, string $label, int $length): string
    {
        $id = '';
        $block = 0;

        while (\strlen($id) < $length) {
            $id .= \hash_hmac('sha256', $label . '|' . $block, $derivedKey, true);
            ++$block;
        }

        return \substr($id, 0, $length);
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
            $publicKeyCredential = $this->ceremonyFactory->getSerializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

            if (!$publicKeyCredential instanceof PublicKeyCredential) {
                return null;
            }

            $credential = $this->credentialRepository->findByCredentialId($publicKeyCredential->rawId);

            if (!$credential instanceof Credential || $credential->isRevoked()) {
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
    public function verifyAssertionResponse(string $responseJson, string $challengeToken, int $beUserUid): VerifiedAssertion
    {
        $challenge = $this->challengeService->verifyChallengeToken($challengeToken);
        $rpId = $this->configService->getEffectiveRpId();
        // Deserialize the browser response
        $publicKeyCredential = $this->ceremonyFactory->getSerializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

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

        if (!$credential instanceof Credential) {
            $this->logger->warning('Assertion with unknown credential ID', ['be_user_uid' => $beUserUid]);

            throw new RuntimeException('Unknown credential', 1700000032);
        }

        if ($credential->isRevoked()) {
            throw new RuntimeException('Credential has been revoked', 1700000033);
        }

        if ($credential->getBeUser() !== $beUserUid) {
            $this->logger->warning(
                'Credential does not belong to the claimed user',
                ['be_user_uid' => $beUserUid, 'credential_be_user' => $credential->getBeUser()],
            );

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
            $this->logger->error('Passkey assertion verification failed', ['be_user_uid' => $beUserUid, 'error' => $e->getMessage()]);

            throw new RuntimeException('Assertion verification failed: ' . $e->getMessage(), 1700000035, $e);
        }

        $this->credentialRepository->updateSignCount($credential->getUid(), $updatedSource->counter);
        $this->credentialRepository->updateLastUsed($credential->getUid());
        $this->logger->info('Passkey login successful', ['be_user_uid' => $beUserUid, 'credential_uid' => $credential->getUid()]);

        return new VerifiedAssertion(credential: $credential, source: $updatedSource);
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
        $aaguid = $credential->getAaguid() !== '' ? Uuid::fromString($credential->getAaguid()) : Uuid::v4();

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
