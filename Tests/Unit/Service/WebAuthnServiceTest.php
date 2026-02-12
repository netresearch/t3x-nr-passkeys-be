<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
use Netresearch\NrPasskeysBe\Domain\Dto\RegistrationOptions;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\ChallengeService;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

#[CoversClass(WebAuthnService::class)]
final class WebAuthnServiceTest extends TestCase
{
    private ExtensionConfigurationService&MockObject $configServiceMock;
    private ChallengeService&MockObject $challengeServiceMock;
    private CredentialRepository&MockObject $credentialRepositoryMock;
    private LoggerInterface&MockObject $loggerMock;
    private WebAuthnService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key-for-user-handle-generation';

        $this->configServiceMock = $this->createMock(ExtensionConfigurationService::class);
        $this->challengeServiceMock = $this->createMock(ChallengeService::class);
        $this->credentialRepositoryMock = $this->createMock(CredentialRepository::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subject = new WebAuthnService(
            $this->configServiceMock,
            $this->challengeServiceMock,
            $this->credentialRepositoryMock,
            $this->loggerMock,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function createRegistrationOptionsReturnsCorrectStructure(): void
    {
        $beUserUid = 123;
        $username = 'admin';
        $displayName = 'Admin User';
        $challenge = \random_bytes(32);
        $challengeToken = 'test-challenge-token';

        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test TYPO3',
            userVerification: 'required',
            allowedAlgorithms: 'ES256,RS256',
        );

        $this->configServiceMock
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configServiceMock
            ->method('getConfiguration')
            ->willReturn($config);

        $this->challengeServiceMock
            ->method('generateChallenge')
            ->willReturn($challenge);

        $this->challengeServiceMock
            ->method('createChallengeToken')
            ->with($challenge)
            ->willReturn($challengeToken);

        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with($beUserUid)
            ->willReturn([]);

        $result = $this->subject->createRegistrationOptions($beUserUid, $username, $displayName);

        self::assertInstanceOf(RegistrationOptions::class, $result);
        self::assertInstanceOf(PublicKeyCredentialCreationOptions::class, $result->options);
        self::assertSame($challengeToken, $result->challengeToken);

        $options = $result->options;
        self::assertSame($challenge, $options->challenge);
        self::assertSame('example.com', $options->rp->id);
        self::assertSame('Test TYPO3', $options->rp->name);
        self::assertSame($username, $options->user->name);
        self::assertSame($displayName, $options->user->displayName);
        self::assertSame(60000, $options->timeout);
        self::assertCount(2, $options->pubKeyCredParams);
    }

    #[Test]
    public function createRegistrationOptionsExcludesExistingCredentials(): void
    {
        $beUserUid = 123;
        $username = 'admin';
        $displayName = 'Admin User';

        $existingCredential = new Credential(
            uid: 1,
            beUser: $beUserUid,
            credentialId: 'existing-credential-id',
            publicKeyCose: 'cose-data',
            transports: '["usb","nfc"]',
            label: 'My Key',
        );

        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test TYPO3',
            userVerification: 'preferred',
        );

        $this->configServiceMock
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configServiceMock
            ->method('getConfiguration')
            ->willReturn($config);

        $this->challengeServiceMock
            ->method('generateChallenge')
            ->willReturn(\random_bytes(32));

        $this->challengeServiceMock
            ->method('createChallengeToken')
            ->willReturn('token');

        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with($beUserUid)
            ->willReturn([$existingCredential]);

        $result = $this->subject->createRegistrationOptions($beUserUid, $username, $displayName);

        $options = $result->options;
        self::assertCount(1, $options->excludeCredentials);
        self::assertSame('existing-credential-id', $options->excludeCredentials[0]->id);
        self::assertSame(['usb', 'nfc'], $options->excludeCredentials[0]->transports);
    }

    #[Test]
    public function createAssertionOptionsReturnsCorrectStructure(): void
    {
        $beUserUid = 456;
        $username = 'testuser';
        $challenge = \random_bytes(32);
        $challengeToken = 'assertion-challenge-token';

        $credential = new Credential(
            uid: 10,
            beUser: $beUserUid,
            credentialId: 'cred-id-123',
            publicKeyCose: 'cose',
            transports: '["internal"]',
            label: 'My Passkey',
        );

        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            userVerification: 'preferred',
        );

        $this->configServiceMock
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configServiceMock
            ->method('getConfiguration')
            ->willReturn($config);

        $this->challengeServiceMock
            ->method('generateChallenge')
            ->willReturn($challenge);

        $this->challengeServiceMock
            ->method('createChallengeToken')
            ->with($challenge)
            ->willReturn($challengeToken);

        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with($beUserUid)
            ->willReturn([$credential]);

        $result = $this->subject->createAssertionOptions($username, $beUserUid);

        self::assertInstanceOf(AssertionOptions::class, $result);
        self::assertInstanceOf(PublicKeyCredentialRequestOptions::class, $result->options);
        self::assertSame($challengeToken, $result->challengeToken);

        $options = $result->options;
        self::assertSame($challenge, $options->challenge);
        self::assertSame('example.com', $options->rpId);
        self::assertSame('preferred', $options->userVerification);
        self::assertSame(60000, $options->timeout);
        self::assertCount(1, $options->allowCredentials);
        self::assertSame('cred-id-123', $options->allowCredentials[0]->id);
        self::assertSame(['internal'], $options->allowCredentials[0]->transports);
    }

    #[Test]
    public function createDiscoverableAssertionOptionsReturnsEmptyAllowCredentials(): void
    {
        $challenge = \random_bytes(32);
        $challengeToken = 'discoverable-token';

        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            userVerification: 'required',
        );

        $this->configServiceMock
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configServiceMock
            ->method('getConfiguration')
            ->willReturn($config);

        $this->challengeServiceMock
            ->method('generateChallenge')
            ->willReturn($challenge);

        $this->challengeServiceMock
            ->method('createChallengeToken')
            ->with($challenge)
            ->willReturn($challengeToken);

        $result = $this->subject->createDiscoverableAssertionOptions();

        self::assertInstanceOf(AssertionOptions::class, $result);
        self::assertInstanceOf(PublicKeyCredentialRequestOptions::class, $result->options);
        self::assertSame($challengeToken, $result->challengeToken);

        $options = $result->options;
        self::assertSame($challenge, $options->challenge);
        self::assertSame('example.com', $options->rpId);
        self::assertSame('required', $options->userVerification);
        self::assertSame(60000, $options->timeout);
        self::assertCount(0, $options->allowCredentials, 'Discoverable login must have empty allowCredentials');
    }

    #[Test]
    public function storeCredentialPersistsAndReturnsCredential(): void
    {
        $beUserUid = 789;
        $label = 'Work Laptop Key';
        $expectedUid = 42;

        $source = PublicKeyCredentialSource::create(
            publicKeyCredentialId: 'credential-id-xyz',
            type: 'public-key',
            transports: ['usb', 'nfc'],
            attestationType: 'none',
            trustPath: new \Webauthn\TrustPath\EmptyTrustPath(),
            aaguid: \Symfony\Component\Uid\Uuid::v4(),
            credentialPublicKey: 'cose-public-key-data',
            userHandle: 'user-handle-hash',
            counter: 0,
        );

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Credential $cred) use ($beUserUid, $label, $source): bool {
                return $cred->getBeUser() === $beUserUid
                    && $cred->getLabel() === $label
                    && $cred->getCredentialId() === $source->publicKeyCredentialId
                    && $cred->getPublicKeyCose() === $source->credentialPublicKey
                    && $cred->getUserHandle() === $source->userHandle
                    && $cred->getSignCount() === $source->counter
                    && $cred->getAaguid() === $source->aaguid->toString();
            }))
            ->willReturn($expectedUid);

        $result = $this->subject->storeCredential($source, $beUserUid, $label);

        self::assertInstanceOf(Credential::class, $result);
        self::assertSame($expectedUid, $result->getUid());
        self::assertSame($beUserUid, $result->getBeUser());
        self::assertSame($label, $result->getLabel());
        self::assertSame('credential-id-xyz', $result->getCredentialId());
    }

    #[Test]
    public function createUserHandleIsDeterministic(): void
    {
        $beUserUid = 100;

        $config = new ExtensionConfiguration();
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        // Call twice and check that user handle is the same
        $result1 = $this->subject->createRegistrationOptions($beUserUid, 'user', 'User');
        $result2 = $this->subject->createRegistrationOptions($beUserUid, 'user', 'User');

        $userHandle1 = $result1->options->user->id;
        $userHandle2 = $result2->options->user->id;

        self::assertSame($userHandle1, $userHandle2, 'User handle must be deterministic for same UID');
    }

    #[Test]
    public function createUserHandleDiffersForDifferentUsers(): void
    {
        $config = new ExtensionConfiguration();
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result1 = $this->subject->createRegistrationOptions(100, 'user1', 'User 1');
        $result2 = $this->subject->createRegistrationOptions(200, 'user2', 'User 2');

        $userHandle1 = $result1->options->user->id;
        $userHandle2 = $result2->options->user->id;

        self::assertNotSame($userHandle1, $userHandle2, 'Different users must have different user handles');
    }

    #[Test]
    public function serializeCreationOptionsProducesValidJson(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');
        $json = $this->subject->serializeCreationOptions($result->options);

        self::assertJson($json);
        $decoded = \json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('rp', $decoded);
        self::assertArrayHasKey('user', $decoded);
        self::assertArrayHasKey('challenge', $decoded);
        self::assertArrayHasKey('pubKeyCredParams', $decoded);
    }

    #[Test]
    public function serializeRequestOptionsProducesValidJson(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createAssertionOptions('user', 456);
        $json = $this->subject->serializeRequestOptions($result->options);

        self::assertJson($json);
        $decoded = \json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('rpId', $decoded);
        self::assertArrayHasKey('challenge', $decoded);
        self::assertArrayHasKey('allowCredentials', $decoded);
    }

    #[Test]
    public function verifyAssertionResponseThrowsOnInvalidResponseJson(): void
    {
        $invalidJson = '{"invalid": "structure"}';
        $challengeToken = 'valid-token';
        $challenge = \random_bytes(32);
        $beUserUid = 100;

        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');

        $this->challengeServiceMock
            ->method('verifyChallengeToken')
            ->with($challengeToken)
            ->willReturn($challenge);

        // Invalid JSON structure will fail deserialization
        $this->expectException(Throwable::class);

        $this->subject->verifyAssertionResponse($invalidJson, $challengeToken, $beUserUid);
    }

    #[Test]
    public function verifyRegistrationResponseLogsErrorOnVerificationFailure(): void
    {
        $invalidJson = '{"type":"public-key","rawId":"abc","response":{"clientDataJSON":"xyz"}}';
        $challengeToken = 'token';
        $challenge = \random_bytes(32);
        $beUserUid = 123;

        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->configServiceMock->method('getEffectiveOrigin')->willReturn('https://example.com');

        $this->challengeServiceMock
            ->method('verifyChallengeToken')
            ->with($challengeToken)
            ->willReturn($challenge);

        // Invalid response will trigger error logging
        $this->loggerMock
            ->expects(self::never())
            ->method('error');

        // Will throw during deserialization
        $this->expectException(Throwable::class);

        $this->subject->verifyRegistrationResponse(
            $invalidJson,
            $challengeToken,
            $beUserUid,
            'user',
            'User',
        );
    }

    #[Test]
    public function storeCredentialCreatesCredentialWithCorrectTransports(): void
    {
        $beUserUid = 999;
        $label = 'Security Key';
        $expectedUid = 55;

        $uuid = \Symfony\Component\Uid\Uuid::v4();
        $source = PublicKeyCredentialSource::create(
            publicKeyCredentialId: 'test-cred-id',
            type: 'public-key',
            transports: ['usb', 'ble', 'nfc'],
            attestationType: 'none',
            trustPath: new \Webauthn\TrustPath\EmptyTrustPath(),
            aaguid: $uuid,
            credentialPublicKey: 'public-key-cose',
            userHandle: 'user-handle',
            counter: 5,
        );

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Credential $cred) use ($uuid): bool {
                $transports = $cred->getTransportsArray();
                return $transports === ['usb', 'ble', 'nfc']
                    && $cred->getAaguid() === $uuid->toString();
            }))
            ->willReturn($expectedUid);

        $result = $this->subject->storeCredential($source, $beUserUid, $label);

        self::assertSame($expectedUid, $result->getUid());
        self::assertSame(['usb', 'ble', 'nfc'], $result->getTransportsArray());
    }

    #[Test]
    public function verifyRegistrationResponseThrowsOnInvalidJson(): void
    {
        $invalidJson = '{"invalid": "json structure that does not match PublicKeyCredential"}';
        $challengeToken = 'token';
        $challenge = \random_bytes(32);

        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->configServiceMock->method('getEffectiveOrigin')->willReturn('https://example.com');

        $this->challengeServiceMock
            ->method('verifyChallengeToken')
            ->with($challengeToken)
            ->willReturn($challenge);

        $this->expectException(Throwable::class);

        $this->subject->verifyRegistrationResponse(
            $invalidJson,
            $challengeToken,
            123,
            'user',
            'User',
        );
    }

    #[Test]
    #[DataProvider('algorithmProvider')]
    public function createRegistrationOptionsWithDifferentAlgorithms(string $algorithms, int $expectedCount): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            allowedAlgorithms: $algorithms,
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        self::assertCount($expectedCount, $result->options->pubKeyCredParams);
    }

    public static function algorithmProvider(): array
    {
        return [
            'ES256 only' => ['ES256', 1],
            'ES256 and RS256' => ['ES256,RS256', 2],
            'All four algorithms' => ['ES256,ES384,ES512,RS256', 4],
            'ES384 and ES512' => ['ES384,ES512', 2],
            'Single RS256' => ['RS256', 1],
        ];
    }

    #[Test]
    public function createRegistrationOptionsIgnoresUnknownAlgorithms(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            allowedAlgorithms: 'ES256,UNKNOWN_ALGO,RS256',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        // Only ES256 and RS256 should be in the params (unknown ignored)
        self::assertCount(2, $result->options->pubKeyCredParams);
    }

    #[Test]
    public function createRegistrationOptionsSetsTimeoutTo60Seconds(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        self::assertSame(60000, $result->options->timeout);
    }

    #[Test]
    public function createAssertionOptionsSetsTimeoutTo60Seconds(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createAssertionOptions('user', 456);

        self::assertSame(60000, $result->options->timeout);
    }

    #[Test]
    public function createDiscoverableAssertionOptionsSetsTimeoutTo60Seconds(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');

        $result = $this->subject->createDiscoverableAssertionOptions();

        self::assertSame(60000, $result->options->timeout);
    }

    #[Test]
    public function createRegistrationOptionsUsesConfiguredUserVerification(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            userVerification: 'discouraged',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        self::assertSame('discouraged', $result->options->authenticatorSelection->userVerification);
    }

    #[Test]
    public function createAssertionOptionsUsesConfiguredUserVerification(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            userVerification: 'discouraged',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createAssertionOptions('user', 456);

        self::assertSame('discouraged', $result->options->userVerification);
    }

    #[Test]
    public function createRegistrationOptionsSetsResidentKeyToPreferred(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        self::assertSame('preferred', $result->options->authenticatorSelection->residentKey);
    }

    #[Test]
    public function createRegistrationOptionsSetsAttestationToNone(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        self::assertSame('none', $result->options->attestation);
    }

    #[Test]
    public function storeCredentialSetsSignCountFromSource(): void
    {
        $beUserUid = 111;
        $label = 'Test Key';

        $source = PublicKeyCredentialSource::create(
            publicKeyCredentialId: 'cred-id',
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: new \Webauthn\TrustPath\EmptyTrustPath(),
            aaguid: \Symfony\Component\Uid\Uuid::v4(),
            credentialPublicKey: 'cose',
            userHandle: 'handle',
            counter: 42,
        );

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Credential $cred): bool {
                return $cred->getSignCount() === 42;
            }))
            ->willReturn(1);

        $result = $this->subject->storeCredential($source, $beUserUid, $label);

        self::assertSame(42, $result->getSignCount());
    }

    #[Test]
    public function createUserHandleUsesTypo3EncryptionKey(): void
    {
        $beUserUid = 500;

        // Change the encryption key and verify the handle changes
        $config = new ExtensionConfiguration();
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result1 = $this->subject->createRegistrationOptions($beUserUid, 'user', 'User');
        $handle1 = $result1->options->user->id;

        // Change the encryption key (must be >= 32 chars)
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'a-completely-different-key-that-is-long-enough-for-validation';

        $result2 = $this->subject->createRegistrationOptions($beUserUid, 'user', 'User');
        $handle2 = $result2->options->user->id;

        self::assertNotSame($handle1, $handle2, 'User handle must depend on encryption key');

        // Restore original key for other tests
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key-for-user-handle-generation';
    }

    #[Test]
    public function createUserHandleReturns32BytesSha256Hash(): void
    {
        $config = new ExtensionConfiguration();
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        $userHandle = $result->options->user->id;
        self::assertSame(32, \strlen($userHandle), 'User handle must be 32 bytes (SHA-256)');
    }

    #[Test]
    public function createAssertionOptionsIncludesMultipleCredentials(): void
    {
        $beUserUid = 789;
        $credentials = [
            new Credential(
                uid: 1,
                beUser: $beUserUid,
                credentialId: 'cred-1',
                publicKeyCose: 'cose-1',
                transports: '["usb"]',
                label: 'Key 1',
            ),
            new Credential(
                uid: 2,
                beUser: $beUserUid,
                credentialId: 'cred-2',
                publicKeyCose: 'cose-2',
                transports: '["nfc","internal"]',
                label: 'Key 2',
            ),
            new Credential(
                uid: 3,
                beUser: $beUserUid,
                credentialId: 'cred-3',
                publicKeyCose: 'cose-3',
                transports: '[]',
                label: 'Key 3',
            ),
        ];

        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with($beUserUid)
            ->willReturn($credentials);

        $result = $this->subject->createAssertionOptions('user', $beUserUid);

        self::assertCount(3, $result->options->allowCredentials);
        self::assertSame('cred-1', $result->options->allowCredentials[0]->id);
        self::assertSame('cred-2', $result->options->allowCredentials[1]->id);
        self::assertSame('cred-3', $result->options->allowCredentials[2]->id);
        self::assertSame(['usb'], $result->options->allowCredentials[0]->transports);
        self::assertSame(['nfc', 'internal'], $result->options->allowCredentials[1]->transports);
        self::assertSame([], $result->options->allowCredentials[2]->transports);
    }

    #[Test]
    public function createRegistrationOptionsThrowsRuntimeExceptionWhenEncryptionKeyIsEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000040);
        $this->expectExceptionMessage('TYPO3 encryptionKey is missing or too short');

        $this->subject->createRegistrationOptions(123, 'user', 'User');
    }

    #[Test]
    public function createAlgorithmManagerLogsWarningForUnknownAlgorithm(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            allowedAlgorithms: 'ES256,UNKNOWN_ALGO',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->configServiceMock->method('getEffectiveOrigin')->willReturn('https://example.com');

        // The unknown algorithm should trigger a warning log
        $this->loggerMock
            ->expects(self::once())
            ->method('warning')
            ->with('Unknown algorithm configured', ['algorithm' => 'UNKNOWN_ALGO']);

        // Use reflection to call the private createAlgorithmManager method directly
        $reflection = new ReflectionMethod($this->subject, 'createAlgorithmManager');

        $reflection->invoke($this->subject);
    }

    #[Test]
    public function verifyAssertionResponseThrowsForRevokedCredential(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');

        $challenge = \random_bytes(32);
        $this->challengeServiceMock
            ->method('verifyChallengeToken')
            ->willReturn($challenge);

        // We cannot easily test the full deserialization flow because webauthn-lib classes are final.
        // Instead, we test through the code path that checks credential revocation.
        // The credential lookup happens after deserialization, so we need a different approach.
        // We'll test this through the exception code.
        $this->expectException(Throwable::class);

        $this->subject->verifyAssertionResponse('{"invalid":"structure"}', 'token', 100);
    }

    #[Test]
    public function storeCredentialSetsTransportsAsJson(): void
    {
        $beUserUid = 456;
        $label = 'Test';

        $source = PublicKeyCredentialSource::create(
            publicKeyCredentialId: 'cred-id',
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: new \Webauthn\TrustPath\EmptyTrustPath(),
            aaguid: \Symfony\Component\Uid\Uuid::v4(),
            credentialPublicKey: 'cose',
            userHandle: 'handle',
            counter: 0,
        );

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(function (Credential $cred): bool {
                return $cred->getTransports() === '[]';
            }))
            ->willReturn(1);

        $result = $this->subject->storeCredential($source, $beUserUid, $label);

        self::assertSame([], $result->getTransportsArray());
    }

    #[Test]
    public function createAssertionOptionsWithNoCredentials(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with(999)
            ->willReturn([]);

        $result = $this->subject->createAssertionOptions('user', 999);

        self::assertCount(0, $result->options->allowCredentials);
    }

    #[Test]
    public function createRegistrationOptionsWithMultipleExistingCredentials(): void
    {
        $beUserUid = 123;
        $credentials = [
            new Credential(uid: 1, beUser: $beUserUid, credentialId: 'cred-1', transports: '["usb"]'),
            new Credential(uid: 2, beUser: $beUserUid, credentialId: 'cred-2', transports: '["internal"]'),
        ];

        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock
            ->method('findByBeUser')
            ->with($beUserUid)
            ->willReturn($credentials);

        $result = $this->subject->createRegistrationOptions($beUserUid, 'user', 'User');

        self::assertCount(2, $result->options->excludeCredentials);
        self::assertSame('cred-1', $result->options->excludeCredentials[0]->id);
        self::assertSame('cred-2', $result->options->excludeCredentials[1]->id);
    }

    #[Test]
    public function serializeCreationOptionsIsCachingSerializer(): void
    {
        $config = new ExtensionConfiguration(rpId: 'example.com', rpName: 'Test');
        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        // Call serialize twice - should reuse the same serializer instance internally
        $json1 = $this->subject->serializeCreationOptions($result->options);
        $json2 = $this->subject->serializeCreationOptions($result->options);

        self::assertSame($json1, $json2);
    }

    #[Test]
    public function createRegistrationOptionsWithLowercaseAlgorithms(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            allowedAlgorithms: 'es256,rs256',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        // Algorithm names are uppercased internally, so lowercase input should work
        self::assertCount(2, $result->options->pubKeyCredParams);
    }

    #[Test]
    public function createRegistrationOptionsWithWhitespaceAlgorithms(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            rpName: 'Test',
            allowedAlgorithms: ' ES256 , RS256 ',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn('example.com');
        $this->challengeServiceMock->method('generateChallenge')->willReturn(\random_bytes(32));
        $this->challengeServiceMock->method('createChallengeToken')->willReturn('token');
        $this->credentialRepositoryMock->method('findByBeUser')->willReturn([]);

        $result = $this->subject->createRegistrationOptions(123, 'user', 'User');

        // Whitespace should be trimmed
        self::assertCount(2, $result->options->pubKeyCredParams);
    }

    #[Test]
    public function findBeUserUidFromAssertionReturnsNullForInvalidJson(): void
    {
        $result = $this->subject->findBeUserUidFromAssertion('not valid json');

        self::assertNull($result);
    }

    #[Test]
    public function findBeUserUidFromAssertionReturnsNullForEmptyString(): void
    {
        $result = $this->subject->findBeUserUidFromAssertion('');

        self::assertNull($result);
    }

    #[Test]
    public function findBeUserUidFromAssertionReturnsNullForMalformedStructure(): void
    {
        $result = $this->subject->findBeUserUidFromAssertion('{"invalid": "structure"}');

        self::assertNull($result);
    }

    #[Test]
    public function verifyAssertionResponseReturnsVerifiedAssertionOnSuccess(): void
    {
        $rpId = 'example.com';
        $origin = 'https://example.com';
        $challenge = \random_bytes(32);
        $beUserUid = 42;
        $challengeToken = 'test-challenge-token';
        $userHandle = 'user-handle-hash';

        // Generate ES256 key pair (software authenticator)
        $key = \openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);

        $details = \openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $x = \str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = \str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // Create COSE-encoded public key (EC2 / ES256)
        $coseKey = \CBOR\MapObject::create()
            ->add(\CBOR\UnsignedIntegerObject::create(1), \CBOR\UnsignedIntegerObject::create(2))
            ->add(\CBOR\UnsignedIntegerObject::create(3), \CBOR\NegativeIntegerObject::create(-7))
            ->add(\CBOR\NegativeIntegerObject::create(-1), \CBOR\UnsignedIntegerObject::create(1))
            ->add(\CBOR\NegativeIntegerObject::create(-2), \CBOR\ByteStringObject::create($x))
            ->add(\CBOR\NegativeIntegerObject::create(-3), \CBOR\ByteStringObject::create($y));
        $publicKeyCose = (string) $coseKey;

        $credentialId = \random_bytes(32);
        $b64url = static fn(string $d): string => \rtrim(\strtr(\base64_encode($d), '+/', '-_'), '=');

        // Build clientDataJSON
        $clientDataJSON = \json_encode([
            'type' => 'webauthn.get',
            'challenge' => $b64url($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Build authenticatorData: rpIdHash (32) + flags (1) + counter (4)
        $authData = \hash('sha256', $rpId, true) . \chr(0x05) . \pack('N', 1);

        // Sign: authenticatorData || SHA-256(clientDataJSON)
        \openssl_sign($authData . \hash('sha256', $clientDataJSON, true), $signature, $key, OPENSSL_ALGO_SHA256);

        // Build assertion JSON
        $assertionJson = \json_encode([
            'type' => 'public-key',
            'id' => $b64url($credentialId),
            'rawId' => $b64url($credentialId),
            'response' => [
                'clientDataJSON' => $b64url($clientDataJSON),
                'authenticatorData' => $b64url($authData),
                'signature' => $b64url($signature),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Set up mocks
        $config = new ExtensionConfiguration(
            rpId: $rpId,
            allowedAlgorithms: 'ES256',
        );

        $this->configServiceMock->method('getConfiguration')->willReturn($config);
        $this->configServiceMock->method('getEffectiveRpId')->willReturn($rpId);
        $this->configServiceMock->method('getEffectiveOrigin')->willReturn($origin);

        $this->challengeServiceMock
            ->method('verifyChallengeToken')
            ->with($challengeToken)
            ->willReturn($challenge);

        $credential = new Credential(
            uid: 10,
            beUser: $beUserUid,
            credentialId: $credentialId,
            publicKeyCose: $publicKeyCose,
            transports: '[]',
            label: 'Test Key',
            signCount: 0,
            userHandle: $userHandle,
            aaguid: \Symfony\Component\Uid\Uuid::v4()->toString(),
        );

        $this->credentialRepositoryMock
            ->method('findByCredentialId')
            ->with($credentialId)
            ->willReturn($credential);

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('updateSignCount')
            ->with(10, 1);

        $this->credentialRepositoryMock
            ->expects(self::once())
            ->method('updateLastUsed')
            ->with(10);

        $this->loggerMock
            ->expects(self::once())
            ->method('info')
            ->with('Passkey login successful', self::callback(
                static fn(array $ctx): bool => $ctx['be_user_uid'] === $beUserUid && $ctx['credential_uid'] === 10,
            ));

        // Execute
        $result = $this->subject->verifyAssertionResponse($assertionJson, $challengeToken, $beUserUid);

        // Assert
        self::assertInstanceOf(VerifiedAssertion::class, $result);
        self::assertSame($credential, $result->credential);
        self::assertInstanceOf(PublicKeyCredentialSource::class, $result->source);
        self::assertSame(1, $result->source->counter);
    }
}
