<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Controller\ManagementController;
use Netresearch\NrPasskeysBe\Domain\Dto\RegistrationOptions;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

#[CoversClass(ManagementController::class)]
final class ManagementControllerTest extends TestCase
{
    private ManagementController $subject;

    private WebAuthnService&MockObject $webAuthnService;

    private CredentialRepository&MockObject $credentialRepository;

    private ExtensionConfigurationService&MockObject $configService;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(WebAuthnService::class);
        $this->credentialRepository = $this->createMock(CredentialRepository::class);
        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $extensionConfig = new ExtensionConfiguration(
            disablePasswordLogin: false,
        );
        $this->configService
            ->method('getConfiguration')
            ->willReturn($extensionConfig);

        $this->subject = new ManagementController(
            $this->webAuthnService,
            $this->credentialRepository,
            $this->configService,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function registrationOptionsActionSuccess(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $rp = PublicKeyCredentialRpEntity::create(name: 'TYPO3', id: 'example.com');
        $user = PublicKeyCredentialUserEntity::create(name: 'admin', id: 'user-handle', displayName: 'Admin User');
        $options = PublicKeyCredentialCreationOptions::create(
            rp: $rp,
            user: $user,
            challenge: \random_bytes(32),
        );

        $this->webAuthnService
            ->expects(self::once())
            ->method('createRegistrationOptions')
            ->with(42, 'admin', 'Admin User')
            ->willReturn(new RegistrationOptions(
                options: $options,
                challengeToken: 'ct_reg_abc',
            ));

        $this->webAuthnService
            ->expects(self::once())
            ->method('serializeCreationOptions')
            ->with($options)
            ->willReturn('{"rp":{"name":"TYPO3"},"challenge":"xyz"}');

        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertArrayHasKey('options', $body);
        self::assertSame('ct_reg_abc', $body['challengeToken']);
        self::assertSame('TYPO3', $body['options']['rp']['name']);
    }

    #[Test]
    public function registrationOptionsActionNotAuthenticated(): void
    {
        // No BE_USER in GLOBALS
        unset($GLOBALS['BE_USER']);
        $request = $this->createJsonRequest([]);

        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Not authenticated', $body['error']);
    }

    #[Test]
    public function registrationVerifyActionSuccess(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');

        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => ['attestationObject' => 'abc']],
            'challengeToken' => 'ct_reg_abc',
            'label' => 'My YubiKey',
        ]);

        $sourceMock = $this->createMock(PublicKeyCredentialSource::class);
        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyRegistrationResponse')
            ->with(
                self::callback(static function (string $json): bool {
                    $decoded = \json_decode($json, true);
                    return $decoded['id'] === 'cred-xyz';
                }),
                'ct_reg_abc',
                42,
                'admin',
                'Admin User',
            )
            ->willReturn($sourceMock);

        $storedCredential = new Credential(uid: 99, beUser: 42, label: 'My YubiKey');
        $this->webAuthnService
            ->expects(self::once())
            ->method('storeCredential')
            ->with($sourceMock, 42, 'My YubiKey')
            ->willReturn($storedCredential);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
        self::assertSame(99, $body['credential']['uid']);
        self::assertSame('My YubiKey', $body['credential']['label']);
    }

    #[Test]
    public function listActionSuccess(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $cred1 = new Credential(uid: 10, beUser: 42, label: 'Key 1', createdAt: 1700000000);
        $cred2 = new Credential(uid: 11, beUser: 42, label: 'Key 2', createdAt: 1700001000);

        $this->credentialRepository
            ->expects(self::once())
            ->method('findByBeUser')
            ->with(42)
            ->willReturn([$cred1, $cred2]);

        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame(2, $body['count']);
        self::assertCount(2, $body['credentials']);
        self::assertSame('Key 1', $body['credentials'][0]['label']);
        self::assertSame('Key 2', $body['credentials'][1]['label']);
        self::assertFalse($body['enforcementEnabled']);
    }

    #[Test]
    public function renameActionSuccess(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([
            'uid' => 10,
            'label' => 'Renamed Key',
        ]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Old Name');
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $this->credentialRepository
            ->expects(self::once())
            ->method('updateLabel')
            ->with(10, 'Renamed Key');

        $response = $this->subject->renameAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function renameActionUnownedCredential(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([
            'uid' => 999,
            'label' => 'Renamed Key',
        ]);

        // User owns credential 10, not 999
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(999, 42)
            ->willReturn(null);

        $this->credentialRepository
            ->expects(self::never())
            ->method('updateLabel');

        $response = $this->subject->renameAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Credential not found', $body['error']);
    }

    #[Test]
    public function removeActionSuccess(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 10]);

        $cred1 = new Credential(uid: 10, beUser: 42, label: 'Key 1');

        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred1);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(2);

        $this->credentialRepository
            ->expects(self::once())
            ->method('delete')
            ->with(10);

        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function removeActionLastPasskeyBlocked(): void
    {
        // Configure password login as disabled (enforcement enabled)
        $configWithEnforcement = new ExtensionConfiguration(
            disablePasswordLogin: true,
        );
        $configServiceEnforced = $this->createMock(ExtensionConfigurationService::class);
        $configServiceEnforced->method('getConfiguration')->willReturn($configWithEnforcement);

        $this->subject = new ManagementController(
            $this->webAuthnService,
            $this->credentialRepository,
            $configServiceEnforced,
            $this->logger,
        );

        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 10]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Only Key');
        $this->credentialRepository
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $this->credentialRepository
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(1);

        $this->credentialRepository
            ->expects(self::never())
            ->method('delete');

        $response = $this->subject->removeAction($request);

        self::assertSame(409, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertStringContainsString('Cannot remove your last passkey', $body['error']);
    }

    #[Test]
    public function removeActionNotOwned(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 999]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('findByUidAndBeUser')
            ->with(999, 42)
            ->willReturn(null);

        $this->credentialRepository
            ->expects(self::never())
            ->method('delete');

        $response = $this->subject->removeAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Credential not found', $body['error']);
    }

    #[Test]
    public function registrationVerifyActionNotAuthenticated(): void
    {
        unset($GLOBALS['BE_USER']);
        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz'],
            'challengeToken' => 'ct_reg_abc',
        ]);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Not authenticated', $body['error']);
    }

    #[Test]
    public function registrationVerifyActionMissingFields(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function registrationVerifyActionMissingChallengeToken(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz'],
        ]);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function registrationVerifyActionVerificationFails(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => []],
            'challengeToken' => 'ct_reg_abc',
            'label' => 'My Key',
        ]);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyRegistrationResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700000022));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('Passkey registration failed', self::anything());

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Registration failed', $body['error']);
    }

    #[Test]
    public function registrationOptionsActionInternalError(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $this->webAuthnService
            ->expects(self::once())
            ->method('createRegistrationOptions')
            ->willThrowException(new RuntimeException('Internal failure'));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to generate registration options', self::anything());

        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Failed to generate options', $body['error']);
    }

    #[Test]
    public function listActionNotAuthenticated(): void
    {
        unset($GLOBALS['BE_USER']);
        $request = $this->createJsonRequest([]);

        $response = $this->subject->listAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Not authenticated', $body['error']);
    }

    #[Test]
    public function renameActionNotAuthenticated(): void
    {
        unset($GLOBALS['BE_USER']);
        $request = $this->createJsonRequest(['uid' => 10, 'label' => 'New']);

        $response = $this->subject->renameAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Not authenticated', $body['error']);
    }

    #[Test]
    public function renameActionMissingFields(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $response = $this->subject->renameAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function renameActionMissingLabel(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 10]);

        $response = $this->subject->renameAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function removeActionNotAuthenticated(): void
    {
        unset($GLOBALS['BE_USER']);
        $request = $this->createJsonRequest(['uid' => 10]);

        $response = $this->subject->removeAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Not authenticated', $body['error']);
    }

    #[Test]
    public function removeActionMissingUid(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $response = $this->subject->removeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing credential uid', $body['error']);
    }

    #[Test]
    public function removeActionLastPasskeyAllowedWhenPasswordLoginEnabled(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 10]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Only Key');
        $this->credentialRepository
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $this->credentialRepository
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(1);

        $this->credentialRepository
            ->expects(self::once())
            ->method('delete')
            ->with(10);

        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function registrationVerifyActionSanitizesEmptyLabel(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => ['attestationObject' => 'abc']],
            'challengeToken' => 'ct_reg_abc',
            'label' => '   ',
        ]);

        $sourceMock = $this->createMock(PublicKeyCredentialSource::class);
        $this->webAuthnService
            ->method('verifyRegistrationResponse')
            ->willReturn($sourceMock);

        $storedCredential = new Credential(uid: 99, beUser: 42, label: 'Passkey');
        $this->webAuthnService
            ->expects(self::once())
            ->method('storeCredential')
            ->with($sourceMock, 42, 'Passkey')
            ->willReturn($storedCredential);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listActionShowsEnforcementEnabled(): void
    {
        $configWithEnforcement = new ExtensionConfiguration(
            disablePasswordLogin: true,
        );
        $configServiceEnforced = $this->createMock(ExtensionConfigurationService::class);
        $configServiceEnforced->method('getConfiguration')->willReturn($configWithEnforcement);

        $this->subject = new ManagementController(
            $this->webAuthnService,
            $this->credentialRepository,
            $configServiceEnforced,
            $this->logger,
        );

        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest([]);

        $this->credentialRepository
            ->method('findByBeUser')
            ->willReturn([]);

        $response = $this->subject->listAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertTrue($body['enforcementEnabled']);
    }

    #[Test]
    public function getAuthenticatedUserReturnsNullWhenNoUidInUserArray(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['username' => 'admin']; // no uid
        $GLOBALS['BE_USER'] = $backendUser;

        $request = $this->createJsonRequest([]);

        $response = $this->subject->listAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function registrationVerifyActionUsesUsernameAsDisplayNameWhenRealNameEmpty(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', '');

        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => ['attestationObject' => 'abc']],
            'challengeToken' => 'ct_reg_abc',
            'label' => 'My Key',
        ]);

        $sourceMock = $this->createMock(PublicKeyCredentialSource::class);
        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyRegistrationResponse')
            ->with(
                self::isType('string'),
                'ct_reg_abc',
                42,
                'admin',
                'admin', // displayName should be username when realName is empty
            )
            ->willReturn($sourceMock);

        $storedCredential = new Credential(uid: 99, beUser: 42, label: 'My Key');
        $this->webAuthnService
            ->method('storeCredential')
            ->willReturn($storedCredential);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function registrationOptionsActionUsesUsernameAsDisplayNameWhenRealNameEmpty(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', '');
        $request = $this->createJsonRequest([]);

        $this->webAuthnService
            ->expects(self::once())
            ->method('createRegistrationOptions')
            ->with(42, 'admin', 'admin') // displayName should be username when realName is empty
            ->willThrowException(new RuntimeException('test'));

        $response = $this->subject->registrationOptionsAction($request);

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function registrationVerifyActionDefaultsLabelToPasskey(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');

        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => ['attestationObject' => 'abc']],
            'challengeToken' => 'ct_reg_abc',
            // no label provided
        ]);

        $sourceMock = $this->createMock(PublicKeyCredentialSource::class);
        $this->webAuthnService
            ->method('verifyRegistrationResponse')
            ->willReturn($sourceMock);

        $storedCredential = new Credential(uid: 99, beUser: 42, label: 'Passkey');
        $this->webAuthnService
            ->expects(self::once())
            ->method('storeCredential')
            ->with($sourceMock, 42, 'Passkey')
            ->willReturn($storedCredential);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function registrationVerifyActionTruncatesLabelTo128Chars(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');

        $longLabel = \str_repeat('A', 200);
        $request = $this->createJsonRequest([
            'credential' => ['id' => 'cred-xyz', 'response' => ['attestationObject' => 'abc']],
            'challengeToken' => 'ct_reg_abc',
            'label' => $longLabel,
        ]);

        $sourceMock = $this->createMock(PublicKeyCredentialSource::class);
        $this->webAuthnService
            ->method('verifyRegistrationResponse')
            ->willReturn($sourceMock);

        $expectedLabel = \str_repeat('A', 128);
        $storedCredential = new Credential(uid: 99, beUser: 42, label: $expectedLabel);
        $this->webAuthnService
            ->expects(self::once())
            ->method('storeCredential')
            ->with($sourceMock, 42, $expectedLabel)
            ->willReturn($storedCredential);

        $response = $this->subject->registrationVerifyAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function renameActionTruncatesLabelTo128Chars(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');

        $longLabel = \str_repeat('B', 200);
        $request = $this->createJsonRequest([
            'uid' => 10,
            'label' => $longLabel,
        ]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Old Name');
        $this->credentialRepository
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $expectedLabel = \str_repeat('B', 128);
        $this->credentialRepository
            ->expects(self::once())
            ->method('updateLabel')
            ->with(10, $expectedLabel);

        $response = $this->subject->renameAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function removeActionAllowedWhenMultiplePasskeysAndEnforcementEnabled(): void
    {
        $configWithEnforcement = new ExtensionConfiguration(
            disablePasswordLogin: true,
        );
        $configServiceEnforced = $this->createMock(ExtensionConfigurationService::class);
        $configServiceEnforced->method('getConfiguration')->willReturn($configWithEnforcement);

        $this->subject = new ManagementController(
            $this->webAuthnService,
            $this->credentialRepository,
            $configServiceEnforced,
            $this->logger,
        );

        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['uid' => 10]);

        $cred = new Credential(uid: 10, beUser: 42, label: 'Key 1');
        $this->credentialRepository
            ->method('findByUidAndBeUser')
            ->with(10, 42)
            ->willReturn($cred);

        $this->credentialRepository
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(2);

        $this->credentialRepository
            ->expects(self::once())
            ->method('delete')
            ->with(10);

        $response = $this->subject->removeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function renameActionMissingUid(): void
    {
        $this->setUpAuthenticatedUser(42, 'admin', 'Admin User');
        $request = $this->createJsonRequest(['label' => 'New Name']);

        $response = $this->subject->renameAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    /**
     * Set up GLOBALS['BE_USER'] with a mock backend user.
     */
    private function setUpAuthenticatedUser(int $uid, string $username, string $realName): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => $uid,
            'username' => $username,
            'realName' => $realName,
        ];
        $backendUser->method('isAdmin')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * Create a mock ServerRequestInterface with JSON body data.
     *
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($data);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(\json_encode($data, JSON_THROW_ON_ERROR));
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    /**
     * Decode a PSR-7 response body as JSON.
     *
     * @return array<string, mixed>
     */
    private function decodeResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        return $decoded;
    }
}
