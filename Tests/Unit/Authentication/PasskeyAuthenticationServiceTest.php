<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Authentication;

use Netresearch\NrPasskeysBe\Authentication\PasskeyAuthenticationService;
use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webauthn\PublicKeyCredentialSource;

#[CoversClass(PasskeyAuthenticationService::class)]
final class PasskeyAuthenticationServiceTest extends TestCase
{
    private PasskeyAuthenticationService $subject;

    private WebAuthnService&MockObject $webAuthnService;

    private ExtensionConfigurationService&MockObject $configService;

    private RateLimiterService&MockObject $rateLimiterService;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(WebAuthnService::class);
        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $extensionConfig = new ExtensionConfiguration(
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($extensionConfig);

        // Use addInstance for non-singleton services (used by makeInstance FIFO queue)
        GeneralUtility::addInstance(WebAuthnService::class, $this->webAuthnService);
        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);

        $this->subject = new PasskeyAuthenticationService();
        // Inject logger via the LoggerAwareTrait property inherited from AbstractAuthenticationService
        $this->injectLogger($this->subject, $this->logger);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function authUserWithValidPasskeyDataReturns200(): void
    {
        $credential = new Credential(uid: 10, beUser: 1, label: 'Test Key');
        $this->setUpPasskeyRequest('{"valid":"assertion"}', 'challenge-token-123');
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout');

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                responseJson: '{"valid":"assertion"}',
                challengeToken: 'challenge-token-123',
                beUserUid: 42,
            )
            ->willReturn([
                'credential' => $credential,
                'source' => $this->createMock(PublicKeyCredentialSource::class),
            ]);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('admin', self::anything());

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(200, $result);
    }

    #[Test]
    public function authUserWithInvalidPasskeyDataReturns0(): void
    {
        $this->setUpPasskeyRequest('{"bad":"data"}', 'challenge-token-123');
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout');

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Assertion failed', 1700000035));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('admin', self::anything());

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserWithoutPasskeyDataReturns100(): void
    {
        $this->setUpRequestWithoutPasskey();
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserRecordsFailureOnError(): void
    {
        $this->setUpPasskeyRequest('{"bad":"data"}', 'challenge-token-123');
        $this->subject->login = [
            'uname' => 'testuser',
        ];

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700000035));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('testuser', self::anything());

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Passkey authentication failed', self::anything());

        $user = ['uid' => 5, 'username' => 'testuser'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function authUserClearsLockoutOnSuccess(): void
    {
        $credential = new Credential(uid: 10, beUser: 1, label: 'Test Key');
        $this->setUpPasskeyRequest('{"ok":"assertion"}', 'token-abc');
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willReturn([
                'credential' => $credential,
                'source' => $this->createMock(PublicKeyCredentialSource::class),
            ]);

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('admin', self::anything());

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(200, $result);
    }

    #[Test]
    public function authUserRespectsLockout(): void
    {
        $this->setUpPasskeyRequest('{"ok":"assertion"}', 'token-abc');
        $this->subject->login = [
            'uname' => 'locked_user',
        ];

        $this->rateLimiterService
            ->expects(self::once())
            ->method('checkLockout')
            ->willThrowException(new RuntimeException('Account locked', 1700000011));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('locked_user', self::anything());

        $this->webAuthnService
            ->expects(self::never())
            ->method('verifyAssertionResponse');

        $user = ['uid' => 7, 'username' => 'locked_user'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function getUserWithExistingUser(): void
    {
        $this->setUpPasskeyRequest('{"assertion":"data"}', 'token-123');

        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'admin',
        ];
        $this->injectLogger($service, $this->logger);

        // Provide instances for getUser's configService call
        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);

        $expectedUser = ['uid' => 42, 'username' => 'admin'];

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('admin')
            ->willReturn($expectedUser);

        $result = $service->getUser();

        self::assertSame($expectedUser, $result);
    }

    #[Test]
    public function getUserBlocksNonPasskeyWhenPasswordDisabled(): void
    {
        $configWithPasswordDisabled = new ExtensionConfiguration(
            disablePasswordLogin: true,
        );
        $configServiceDisabled = $this->createMock(ExtensionConfigurationService::class);
        $configServiceDisabled
            ->method('getConfiguration')
            ->willReturn($configWithPasswordDisabled);
        GeneralUtility::addInstance(ExtensionConfigurationService::class, $configServiceDisabled);

        $this->setUpRequestWithoutPasskey();
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function authUserWithoutChallengeTokenReturns0(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'passkey_assertion' => '{"some":"assertion"}',
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->subject->login = [
            'uname' => 'admin',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function getUserWithEmptyUsernameReturnsFalse(): void
    {
        $this->setUpPasskeyRequest('{"assertion":"data"}', 'token-123');
        $this->subject->login = [
            'uname' => '',
        ];

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithUnknownUserReturnsFalse(): void
    {
        $this->setUpPasskeyRequest('{"assertion":"data"}', 'token-123');

        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'nonexistent',
        ];
        $this->injectLogger($service, $this->logger);

        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('nonexistent')
            ->willReturn(false);

        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Passkey login attempt for unknown user', self::anything());

        $result = $service->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithoutPasskeyAndPasswordEnabledReturnsFalse(): void
    {
        $this->setUpRequestWithoutPasskey();
        $this->subject->login = [
            'uname' => 'admin',
        ];

        // Default config has disablePasswordLogin=false
        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getPasskeyAssertionFromLoginArrayWhenNoRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->subject->login = [
            'uname' => 'admin',
            'passkey_assertion' => '{"from":"login_array"}',
            'passkey_challenge_token' => 'token-from-login',
        ];

        // Need to provide instances for getUser dependencies
        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);

        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'admin',
            'passkey_assertion' => '{"from":"login_array"}',
            'passkey_challenge_token' => 'token-from-login',
        ];
        $this->injectLogger($service, $this->logger);

        $expectedUser = ['uid' => 42, 'username' => 'admin'];

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('admin')
            ->willReturn($expectedUser);

        $result = $service->getUser();

        self::assertSame($expectedUser, $result);
    }

    #[Test]
    public function getPasskeyAssertionFallsBackToLoginArrayWhenNotInParsedBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'username' => 'admin',
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->subject->login = [
            'uname' => 'admin',
            'passkey_assertion' => '{"from":"fallback"}',
            'passkey_challenge_token' => 'token-fallback',
        ];

        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);

        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'admin',
            'passkey_assertion' => '{"from":"fallback"}',
            'passkey_challenge_token' => 'token-fallback',
        ];
        $this->injectLogger($service, $this->logger);

        $expectedUser = ['uid' => 42, 'username' => 'admin'];

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('admin')
            ->willReturn($expectedUser);

        $result = $service->getUser();

        self::assertSame($expectedUser, $result);
    }

    #[Test]
    public function getPasskeyAssertionReturnsNullWhenNotInLoginArray(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->subject->login = [
            'uname' => 'admin',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function getChallengeTokenFallsBackToLoginArrayWhenNotInParsedBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'passkey_assertion' => '{"data":"test"}',
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->subject->login = [
            'uname' => 'admin',
            'passkey_challenge_token' => 'token-from-login',
        ];

        $credential = new Credential(uid: 10, beUser: 1, label: 'Test Key');
        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willReturn([
                'credential' => $credential,
                'source' => $this->createMock(PublicKeyCredentialSource::class),
            ]);

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(200, $result);
    }

    #[Test]
    public function getChallengeTokenReturnsNullWhenNoRequestAndNotInLogin(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->subject->login = [
            'uname' => 'admin',
            'passkey_assertion' => '{"data":"test"}',
        ];

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Passkey assertion without challenge token');

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(0, $result);
    }

    #[Test]
    public function getPasskeyAssertionFromParsedBodyWithNonArrayBody(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn('not-an-array');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $this->subject->login = [
            'uname' => 'admin',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        // No passkey_assertion in login array, non-array parsed body -> returns null -> 100
        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function getUserWithMissingUnameKeyReturnsFalse(): void
    {
        $this->setUpPasskeyRequest('{"assertion":"data"}', 'token-123');
        $this->subject->login = [];

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    private function setUpPasskeyRequest(string $assertion, string $challengeToken): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'passkey_assertion' => $assertion,
            'passkey_challenge_token' => $challengeToken,
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
    }

    private function setUpRequestWithoutPasskey(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'username' => 'admin',
            'userident' => 'password123',
        ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
    }

    private function injectLogger(object $service, LoggerInterface $logger): void
    {
        $reflection = new ReflectionClass($service);
        $parent = $reflection;
        while ($parent !== false) {
            if ($parent->hasProperty('logger')) {
                $prop = $parent->getProperty('logger');
                $prop->setValue($service, $logger);
                return;
            }
            $parent = $parent->getParentClass();
        }
    }
}
