<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Authentication;

use Netresearch\NrPasskeysBe\Authentication\PasskeyAuthenticationService;
use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
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

    // --- Passkey payload encoding helper ---

    /**
     * Build a passkey payload JSON string as the JS would put into userident.
     *
     * @param array<string, mixed> $assertion
     */
    private static function buildPasskeyUident(array $assertion, string $challengeToken = 'challenge-token-123'): string
    {
        return \json_encode([
            '_type' => 'passkey',
            'assertion' => $assertion,
            'challengeToken' => $challengeToken,
        ], JSON_THROW_ON_ERROR);
    }

    // --- authUser tests ---

    #[Test]
    public function authUserWithValidPasskeyDataReturns200(): void
    {
        $credential = new Credential(uid: 10, beUser: 1, label: 'Test Key');
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => self::buildPasskeyUident(['valid' => 'assertion']),
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
            ->willReturn(new VerifiedAssertion(
                credential: $credential,
                source: $this->createMock(PublicKeyCredentialSource::class),
            ));

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
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => self::buildPasskeyUident(['bad' => 'data']),
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
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => 'regularPassword123',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserRecordsFailureOnError(): void
    {
        $this->subject->login = [
            'uname' => 'testuser',
            'uident' => self::buildPasskeyUident(['bad' => 'data']),
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
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => self::buildPasskeyUident(['ok' => 'assertion'], 'token-abc'),
        ];

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willReturn(new VerifiedAssertion(
                credential: $credential,
                source: $this->createMock(PublicKeyCredentialSource::class),
            ));

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
        $this->subject->login = [
            'uname' => 'locked_user',
            'uident' => self::buildPasskeyUident(['ok' => 'assertion'], 'token-abc'),
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

    // --- getUser tests ---

    #[Test]
    public function getUserWithExistingUser(): void
    {
        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'admin',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
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

        $this->subject->login = [
            'uname' => 'admin',
            'uident' => 'regularPassword123',
        ];

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithEmptyUsernameAndNoCredentialResolutionReturnsFalse(): void
    {
        $this->subject->login = [
            'uname' => '',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findBeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(null);

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithEmptyUsernameResolvesUserFromCredential(): void
    {
        $this->subject->login = [
            'uname' => '',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findBeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(42);

        $this->setUpFetchUserByUid(42, ['uid' => 42, 'username' => 'admin', 'disable' => 0, 'deleted' => 0]);

        $result = $this->subject->getUser();

        self::assertIsArray($result);
        self::assertSame(42, $result['uid']);
        self::assertSame('admin', $result['username']);
    }

    #[Test]
    public function getUserWithEmptyUsernameReturnsFalseWhenUserNotFoundByUid(): void
    {
        $this->subject->login = [
            'uname' => '',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        $this->webAuthnService
            ->expects(self::once())
            ->method('findBeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(999);

        $this->setUpFetchUserByUid(999, null);

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithUnknownUserReturnsFalse(): void
    {
        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'nonexistent',
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];
        $this->injectLogger($service, $this->logger);

        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);

        $service
            ->expects(self::once())
            ->method('fetchUserRecord')
            ->with('nonexistent')
            ->willReturn(false);

        // info() is called twice: "Passkey login attempt" and "Passkey login attempt for unknown user"
        $infoMessages = [];
        $this->logger
            ->method('info')
            ->willReturnCallback(function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            });

        $result = $service->getUser();

        self::assertFalse($result);
        self::assertContains('Passkey login attempt for unknown user', $infoMessages);
    }

    #[Test]
    public function getUserWithoutPasskeyAndPasswordEnabledReturnsFalse(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => 'regularPassword123',
        ];

        // Default config has disablePasswordLogin=false
        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    #[Test]
    public function getUserWithMissingUnameKeyAttemptsDiscoverableLogin(): void
    {
        $this->subject->login = [
            'uident' => self::buildPasskeyUident(['assertion' => 'data'], 'token-123'),
        ];

        // Empty uname key defaults to '' which triggers discoverable flow
        $this->webAuthnService
            ->expects(self::once())
            ->method('findBeUserUidFromAssertion')
            ->with('{"assertion":"data"}')
            ->willReturn(null);

        $result = $this->subject->getUser();

        self::assertFalse($result);
    }

    // --- Passkey payload parsing edge cases ---

    #[Test]
    public function authUserWithEmptyUidentReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithInvalidJsonUidentReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{not valid json',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithJsonMissingTypeReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"assertion":{"test":true},"challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithJsonWrongTypeReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"password","assertion":{"test":true},"challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithMissingAssertionInPayloadReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithEmptyChallengeTokenInPayloadReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","assertion":{"test":true},"challengeToken":""}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function authUserWithNonObjectAssertionInPayloadReturns100(): void
    {
        $this->subject->login = [
            'uname' => 'admin',
            'uident' => '{"_type":"passkey","assertion":"not-an-object","challengeToken":"token"}',
        ];

        $user = ['uid' => 42, 'username' => 'admin'];

        $result = $this->subject->authUser($user);

        self::assertSame(100, $result);
    }

    #[Test]
    public function payloadIsCachedAcrossGetUserAndAuthUser(): void
    {
        $credential = new Credential(uid: 10, beUser: 1, label: 'Test Key');
        $service = $this->getMockBuilder(PasskeyAuthenticationService::class)
            ->onlyMethods(['fetchUserRecord'])
            ->getMock();

        $service->login = [
            'uname' => 'admin',
            'uident' => self::buildPasskeyUident(['cached' => 'test'], 'cached-token'),
        ];
        $this->injectLogger($service, $this->logger);

        GeneralUtility::addInstance(ExtensionConfigurationService::class, $this->configService);
        GeneralUtility::addInstance(WebAuthnService::class, $this->webAuthnService);
        GeneralUtility::addInstance(RateLimiterService::class, $this->rateLimiterService);

        $expectedUser = ['uid' => 42, 'username' => 'admin'];
        $service->expects(self::once())->method('fetchUserRecord')->willReturn($expectedUser);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                responseJson: '{"cached":"test"}',
                challengeToken: 'cached-token',
                beUserUid: 42,
            )
            ->willReturn(new VerifiedAssertion(
                credential: $credential,
                source: $this->createMock(PublicKeyCredentialSource::class),
            ));

        // Both getUser and authUser should use the same decoded payload
        $user = $service->getUser();
        self::assertIsArray($user);

        $result = $service->authUser($user);
        self::assertSame(200, $result);
    }

    // --- Helper methods ---

    /**
     * Set up ConnectionPool mock for fetchUserByUid.
     *
     * @param array<string, mixed>|null $userRow
     */
    private function setUpFetchUserByUid(int $uid, ?array $userRow): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn($userRow ?? false);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn((string) $uid);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool
            ->method('getQueryBuilderForTable')
            ->with('be_users')
            ->willReturn($queryBuilder);

        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);
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
