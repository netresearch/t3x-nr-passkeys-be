<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\RSA\RS256;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class WebAuthnService
{
    private const ALGORITHM_MAP = [
        'ES256' => -7,
        'ES384' => -35,
        'ES512' => -36,
        'RS256' => -257,
    ];

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly ExtensionConfigurationService $configService,
        private readonly ChallengeService $challengeService,
        private readonly CredentialRepository $credentialRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create registration options for a backend user.
     *
     * @return array{options: PublicKeyCredentialCreationOptions, challengeToken: string}
     */
    public function createRegistrationOptions(int $beUserUid, string $username, string $displayName): array
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

        return [
            'options' => $options,
            'challengeToken' => $challengeToken,
        ];
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
    ): PublicKeyCredentialSource {
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
        $publicKeyCredential = $this->getSerializer()->deserialize(
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

        $factory = $this->createCeremonyFactory();
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
     * Create assertion options for login (Variant A: username-first).
     *
     * @return array{options: PublicKeyCredentialRequestOptions, challengeToken: string}
     */
    public function createAssertionOptions(string $username, int $beUserUid): array
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

        return [
            'options' => $options,
            'challengeToken' => $challengeToken,
        ];
    }

    /**
     * Create assertion options for discoverable login (Variant B: identifierless).
     *
     * @return array{options: PublicKeyCredentialRequestOptions, challengeToken: string}
     */
    public function createDiscoverableAssertionOptions(): array
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

        return [
            'options' => $options,
            'challengeToken' => $challengeToken,
        ];
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
            $publicKeyCredential = $this->getSerializer()->deserialize(
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
     * @return array{credential: Credential, source: PublicKeyCredentialSource}
     * @throws RuntimeException on verification failure
     */
    public function verifyAssertionResponse(
        string $responseJson,
        string $challengeToken,
        int $beUserUid,
    ): array {
        $challenge = $this->challengeService->verifyChallengeToken($challengeToken);
        $rpId = $this->configService->getEffectiveRpId();

        // Deserialize the browser response
        $publicKeyCredential = $this->getSerializer()->deserialize(
            $responseJson,
            PublicKeyCredential::class,
            'json',
        );

        if (!$publicKeyCredential instanceof PublicKeyCredential) {
            throw new RuntimeException('Failed to deserialize assertion response', 1700000030);
        }

        $response = $publicKeyCredential->response;
        if (!$response instanceof \Webauthn\AuthenticatorAssertionResponse) {
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

        $factory = $this->createCeremonyFactory();
        $ceremonyManager = $factory->requestCeremony();
        $validator = AuthenticatorAssertionResponseValidator::create($ceremonyManager);

        try {
            $updatedSource = $validator->check(
                publicKeyCredentialSource: $storedSource,
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

        return [
            'credential' => $credential,
            'source' => $updatedSource,
        ];
    }

    /**
     * Store a verified registration result as a Credential.
     */
    public function storeCredential(
        PublicKeyCredentialSource $source,
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
        return $this->getSerializer()->serialize($options, 'json');
    }

    /**
     * Serialize PublicKeyCredentialRequestOptions to JSON for the browser.
     */
    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->getSerializer()->serialize($options, 'json');
    }

    private function getSerializer(): SerializerInterface
    {
        if ($this->serializer === null) {
            $attestationManager = $this->createAttestationStatementSupportManager();
            $factory = new WebauthnSerializerFactory($attestationManager);
            $this->serializer = $factory->create();
        }

        return $this->serializer;
    }

    private function createAttestationStatementSupportManager(): AttestationStatementSupportManager
    {
        $manager = new AttestationStatementSupportManager();
        $manager->add(new NoneAttestationStatementSupport());

        return $manager;
    }

    private function createCeremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        $origin = $this->configService->getEffectiveOrigin();
        $factory->setAllowedOrigins([$origin]);

        $algorithmManager = $this->createAlgorithmManager();
        $factory->setAlgorithmManager($algorithmManager);

        $factory->setAttestationStatementSupportManager($this->createAttestationStatementSupportManager());

        return $factory;
    }

    private function createAlgorithmManager(): AlgorithmManager
    {
        $algorithms = $this->configService->getConfiguration()->getAllowedAlgorithmsList();
        $manager = AlgorithmManager::create();

        foreach ($algorithms as $algo) {
            match (\strtoupper(\trim($algo))) {
                'ES256' => $manager->add(ES256::create()),
                'ES384' => $manager->add(ES384::create()),
                'ES512' => $manager->add(ES512::create()),
                'RS256' => $manager->add(RS256::create()),
                default => $this->logger->warning('Unknown algorithm configured', ['algorithm' => $algo]),
            };
        }

        return $manager;
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
        $salt = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        if ($salt === '') {
            throw new RuntimeException(
                'TYPO3 encryptionKey is not configured. Required for secure user handle generation.',
                1700000040,
            );
        }

        return \hash('sha256', $beUserUid . '|' . $salt, true);
    }

    private function credentialToSource(Credential $credential): PublicKeyCredentialSource
    {
        $aaguid = $credential->getAaguid() !== ''
            ? \Symfony\Component\Uid\Uuid::fromString($credential->getAaguid())
            : \Symfony\Component\Uid\Uuid::v4();

        return PublicKeyCredentialSource::create(
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
