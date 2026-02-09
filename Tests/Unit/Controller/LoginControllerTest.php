<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Error;
use Netresearch\NrPasskeysBe\Controller\LoginController;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webauthn\PublicKeyCredentialRequestOptions;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    private LoginController $subject;

    private WebAuthnService&MockObject $webAuthnService;

    private ExtensionConfigurationService&MockObject $configService;

    private RateLimiterService&MockObject $rateLimiterService;

    private ConnectionPool&MockObject $connectionPool;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(WebAuthnService::class);
        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subject = new LoginController(
            $this->webAuthnService,
            $this->configService,
            $this->rateLimiterService,
            $this->connectionPool,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function optionsActionWithValidUsername(): void
    {
        $request = $this->createJsonRequest(['username' => 'admin']);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: 'example.com',
        );
        $this->webAuthnService
            ->expects(self::once())
            ->method('createAssertionOptions')
            ->with('admin', 42)
            ->willReturn([
                'options' => $options,
                'challengeToken' => 'ct_abc123',
            ]);

        $this->webAuthnService
            ->expects(self::once())
            ->method('serializeRequestOptions')
            ->with($options)
            ->willReturn('{"challenge":"abc","rpId":"example.com"}');

        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertArrayHasKey('options', $body);
        self::assertSame('ct_abc123', $body['challengeToken']);
        self::assertSame('abc', $body['options']['challenge']);
    }

    #[Test]
    public function optionsActionWithEmptyUsername(): void
    {
        $request = $this->createJsonRequest(['username' => '']);

        $response = $this->subject->optionsAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Username is required', $body['error']);
    }

    #[Test]
    public function optionsActionWithUnknownUser(): void
    {
        $request = $this->createJsonRequest(['username' => 'unknown']);
        $this->setUpFindBeUser('unknown', null);

        $response = $this->subject->optionsAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Authentication failed', $body['error']);
    }

    #[Test]
    public function optionsActionWhenRateLimited(): void
    {
        $request = $this->createJsonRequest(['username' => 'admin']);

        $this->rateLimiterService
            ->method('checkRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded', 1700000010));

        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests', $body['error']);
    }

    #[Test]
    public function verifyActionWithValidAssertion(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '{"id":"cred123","response":{}}',
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                responseJson: '{"id":"cred123","response":{}}',
                challengeToken: 'ct_abc123',
                beUserUid: 42,
            );

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('admin', self::anything());

        $response = $this->subject->verifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function verifyActionWithInvalidAssertion(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '{"bad":"data"}',
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700000035));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('admin', self::anything());

        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Authentication failed', $body['error']);
    }

    #[Test]
    public function verifyActionWithMissingFields(): void
    {
        // Missing assertion and challengeToken
        $request = $this->createJsonRequest(['username' => 'admin']);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionWhenRateLimited(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '{"id":"cred123"}',
            'challengeToken' => 'ct_abc123',
        ]);

        $this->rateLimiterService
            ->method('checkRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded', 1700000010));

        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests', $body['error']);
    }

    #[Test]
    public function optionsActionInternalError(): void
    {
        $request = $this->createJsonRequest(['username' => 'admin']);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->expects(self::once())
            ->method('createAssertionOptions')
            ->with('admin', 42)
            ->willThrowException(new Error('Unexpected internal failure'));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to generate assertion options', self::anything());

        $response = $this->subject->optionsAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Internal error', $body['error']);
    }

    #[Test]
    public function verifyActionWithUnknownUser(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'nonexistent',
            'assertion' => '{"id":"cred123","response":{}}',
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('nonexistent', null);

        $this->webAuthnService
            ->expects(self::never())
            ->method('verifyAssertionResponse');

        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Authentication failed', $body['error']);
    }

    #[Test]
    public function optionsActionWithoutUsernameKey(): void
    {
        $request = $this->createJsonRequest([]);

        $response = $this->subject->optionsAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Username is required', $body['error']);
    }

    #[Test]
    public function optionsActionLockout(): void
    {
        $request = $this->createJsonRequest(['username' => 'lockeduser']);

        $this->rateLimiterService
            ->method('checkLockout')
            ->willThrowException(new RuntimeException('Account locked out', 1700000020));

        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests', $body['error']);
    }

    /**
     * Create a mock ServerRequestInterface with a JSON body parsed into getParsedBody().
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
     * Set up the ConnectionPool mock to simulate finding (or not finding) a BE user.
     *
     * @param array<string, mixed>|null $userRow
     */
    private function setUpFindBeUser(string $username, ?array $userRow): void
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
        $queryBuilder->method('createNamedParameter')->willReturn("'" . $username . "'");
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with('be_users')
            ->willReturn($queryBuilder);
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
