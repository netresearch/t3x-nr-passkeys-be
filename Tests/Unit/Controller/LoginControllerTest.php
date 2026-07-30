<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Error;
use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\Controller\LoginController;
use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
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
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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

    private FrontendInterface&MockObject $loginTokenCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webAuthnService = $this->createMock(WebAuthnService::class);
        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // verifyAction issues a login token into the nonce cache via
        // GeneralUtility::makeInstance(CacheManager::class); stub it out.
        $this->loginTokenCache = $this->createMock(FrontendInterface::class);
        $cacheManagerStub = $this->createStub(CacheManager::class);
        $cacheManagerStub->method('getCache')->willReturn($this->loginTokenCache);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManagerStub);

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
            ->willReturn(new AssertionOptions(
                options: $options,
                challengeToken: 'ct_abc123',
            ));

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

    /**
     * The enumeration oracle F7 closed: the unknown-username branch used to sleep
     * 50-150ms while the known-username branch returned immediately, so the minimum
     * round-trip classified any username. Both branches must now spend the same
     * wall-clock time.
     */
    #[Test]
    public function optionsActionTakesTheSameTimeForKnownAndUnknownUsernames(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: 'example.com',
        );
        $this->webAuthnService
            ->method('createAssertionOptions')
            ->willReturn(new AssertionOptions(options: $options, challengeToken: 'ct_known'));
        $this->webAuthnService
            ->method('createDecoyAssertionOptions')
            ->willReturn(new AssertionOptions(options: $options, challengeToken: 'ct_decoy'));
        $this->webAuthnService
            ->method('serializeRequestOptions')
            ->willReturn('{"challenge":"abc","rpId":"example.com"}');

        $this->setUpFindBeUserMap([
            'admin' => ['uid' => 42, 'username' => 'admin'],
            'nosuchuser' => null,
        ]);

        $knownNs = $this->timeOptionsAction('admin');
        $unknownNs = $this->timeOptionsAction('nosuchuser');

        // Both are padded to the same 150ms budget; allow generous slack for
        // scheduling noise while still failing on the old 50-150ms one-sided delay.
        $budgetNs = 150_000_000;
        self::assertGreaterThan($budgetNs * 0.9, $knownNs, 'Known username must not answer faster than the budget');
        self::assertGreaterThan($budgetNs * 0.9, $unknownNs);
        self::assertLessThan(
            $budgetNs * 0.5,
            \abs($knownNs - $unknownNs),
            'Known and unknown usernames must not differ measurably in response time',
        );
    }

    /**
     * Run optionsAction for $username and return the elapsed nanoseconds.
     */
    private function timeOptionsAction(string $username): float
    {
        $startedAt = \hrtime(true);
        $response = $this->subject->optionsAction($this->createJsonRequest(['username' => $username]));
        $elapsed = (float) (\hrtime(true) - $startedAt);

        self::assertSame(200, $response->getStatusCode());

        return $elapsed;
    }

    #[Test]
    public function optionsActionWithEmptyUsernameWhenDiscoverableDisabled(): void
    {
        $request = $this->createJsonRequest(['username' => '']);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: false));

        $response = $this->subject->optionsAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Username is required', $body['error']);
    }

    #[Test]
    public function optionsActionWithEmptyUsernameWhenDiscoverableEnabled(): void
    {
        $request = $this->createJsonRequest(['username' => '']);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: true));

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: 'example.com',
            allowCredentials: [],
        );

        $this->webAuthnService
            ->expects(self::once())
            ->method('createDiscoverableAssertionOptions')
            ->willReturn(new AssertionOptions(
                options: $options,
                challengeToken: 'ct_discoverable',
            ));

        $this->webAuthnService
            ->expects(self::once())
            ->method('serializeRequestOptions')
            ->with($options)
            ->willReturn('{"challenge":"abc","rpId":"example.com","allowCredentials":[]}');

        $response = $this->subject->optionsAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertArrayHasKey('options', $body);
        self::assertSame('ct_discoverable', $body['challengeToken']);
        self::assertSame([], $body['options']['allowCredentials']);
    }

    #[Test]
    public function optionsActionWithUnknownUserReturnsDecoyOptionsToPreventEnumeration(): void
    {
        $request = $this->createJsonRequest(['username' => 'unknown']);
        $this->setUpFindBeUser('unknown', null);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: 'example.com',
        );
        $this->webAuthnService
            ->expects(self::once())
            ->method('createDecoyAssertionOptions')
            ->with('unknown')
            ->willReturn(new AssertionOptions(
                options: $options,
                challengeToken: 'ct_decoy',
            ));
        $this->webAuthnService
            ->expects(self::once())
            ->method('serializeRequestOptions')
            ->with($options)
            ->willReturn('{"challenge":"abc","rpId":"example.com","allowCredentials":[{"type":"public-key","id":"decoy"}]}');

        $response = $this->subject->optionsAction($request);

        // Same shape and status (200) as a real user -> no enumeration oracle.
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertArrayHasKey('options', $body);
        self::assertSame('ct_decoy', $body['challengeToken']);
        self::assertArrayNotHasKey('error', $body);
    }

    #[Test]
    public function optionsActionWhenRateLimited(): void
    {
        $request = $this->createJsonRequest(['username' => 'admin']);

        $this->rateLimiterService
            ->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded', 1700000010));

        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests. Please try again later.', $body['error']);
        self::assertFalse($body['locked']);
    }

    #[Test]
    public function verifyActionWithValidAssertion(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => ['id' => 'cred123', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->with(
                responseJson: self::isType('string'),
                challengeToken: 'ct_abc123',
                beUserUid: 42,
            );

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordSuccess')
            ->with('admin', self::anything());

        // A verified username-first login yields a single-use token, stored in
        // the nonce cache, that the JS submits through the login form. The stored
        // value carries its own expiry so redemption does not rely on the cache
        // backend honouring the lifetime.
        $issuedAt = \time();
        $this->loginTokenCache
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                self::callback(static function (mixed $value) use ($issuedAt): bool {
                    self::assertIsString($value);
                    $payload = \json_decode($value, true, 8, JSON_THROW_ON_ERROR);
                    self::assertIsArray($payload);
                    self::assertSame(42, $payload['uid'] ?? null);
                    self::assertIsInt($payload['expiresAt'] ?? null);
                    self::assertGreaterThan($issuedAt, $payload['expiresAt']);
                    self::assertLessThanOrEqual($issuedAt + 120 + 2, $payload['expiresAt']);

                    return true;
                }),
                [],
                120,
            );

        $response = $this->subject->verifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
        self::assertIsString($body['loginToken']);
        self::assertNotSame('', $body['loginToken']);
    }

    #[Test]
    public function verifyActionWithInvalidAssertion(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => ['bad' => 'data'],
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

    /**
     * webauthn-lib's deserializer throws Webauthn\Exception\InvalidDataException,
     * which extends \Exception and not \RuntimeException: it used to escape the
     * controller as an uncaught 500 (leaking a stack trace with debug output on) and
     * skipped the recordFailure() bookkeeping, so those attempts did not count
     * towards rate limiting.
     */
    #[Test]
    public function verifyActionMapsNonRuntimeExceptionsToTheGeneric401(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => ['id' => 'AQID', 'rawId' => 'AQID', 'type' => 'public-key', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->expects(self::once())
            ->method('verifyAssertionResponse')
            ->willThrowException(new \Webauthn\Exception\InvalidDataException(
                null,
                'Invalid input',
            ));

        $this->rateLimiterService
            ->expects(self::once())
            ->method('recordFailure')
            ->with('admin', self::anything());

        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Authentication failed', $this->decodeResponse($response)['error']);
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
            'assertion' => ['id' => 'cred123'],
            'challengeToken' => 'ct_abc123',
        ]);

        $this->rateLimiterService
            ->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded', 1700000010));

        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests. Please try again later.', $body['error']);
        self::assertFalse($body['locked']);
    }

    #[Test]
    public function optionsActionDiscoverableLoginRateLimited(): void
    {
        $request = $this->createJsonRequest(['username' => '']);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: true));

        $this->rateLimiterService
            ->method('consumeRateLimit')
            ->willThrowException(new RuntimeException('Rate limit exceeded', 1700000010));

        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Too many requests', $body['error']);
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function optionsActionDiscoverableLoginInternalError(): void
    {
        $request = $this->createJsonRequest(['username' => '']);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: true));

        $this->webAuthnService
            ->expects(self::once())
            ->method('createDiscoverableAssertionOptions')
            ->willThrowException(new Error('Unexpected internal failure'));

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('Failed to generate discoverable assertion options', self::anything());

        $response = $this->subject->optionsAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Internal error', $body['error']);
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
            'assertion' => ['id' => 'cred123', 'response' => []],
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
        // Username-first must never leak a reason (decoy anti-enumeration).
        self::assertArrayNotHasKey('reason', $body);
    }

    #[Test]
    public function verifyActionDiscoverableUnknownCredentialReturnsSignalReason(): void
    {
        $request = $this->createJsonRequest([
            'assertion' => ['id' => 'orphan-cred', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);

        $this->configService->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: true));

        // Credential ID resolves to no user → genuine unknown credential.
        $this->webAuthnService->method('findBeUserUidFromAssertion')->willReturn(null);
        $this->webAuthnService->expects(self::never())->method('verifyAssertionResponse');

        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('unknown_credential', $body['reason']);
    }

    #[Test]
    public function verifyActionDiscoverableValidAssertionIssuesToken(): void
    {
        $request = $this->createJsonRequest([
            'assertion' => ['id' => 'cred123', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);

        $this->configService->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: true));

        $this->webAuthnService->method('findBeUserUidFromAssertion')->willReturn(7);
        $this->webAuthnService->expects(self::once())->method('verifyAssertionResponse')
            ->with(responseJson: self::isType('string'), challengeToken: 'ct_abc123', beUserUid: 7);
        $this->rateLimiterService->expects(self::once())->method('recordSuccess');
        $this->loginTokenCache->expects(self::once())->method('set');

        $response = $this->subject->verifyAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('ok', $body['status']);
        self::assertNotSame('', $body['loginToken']);
    }

    #[Test]
    public function verifyActionDiscoverableRejectedWhenFeatureDisabled(): void
    {
        $request = $this->createJsonRequest([
            'assertion' => ['id' => 'cred123', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);

        $this->configService->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: false));

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function optionsActionWithoutUsernameKeyWhenDiscoverableDisabled(): void
    {
        $request = $this->createJsonRequest([]);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: false));

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
            ->willThrowException(new RuntimeException('Account locked out', 1700000011));

        $response = $this->subject->optionsAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        // UX-3: a lockout (code 1700000011) is reported distinctly from rate limiting.
        self::assertSame('Account temporarily locked. Please contact your administrator.', $body['error']);
        self::assertTrue($body['locked']);
    }

    #[Test]
    public function optionsActionWithNonScalarUsername(): void
    {
        $request = $this->createJsonRequest(['username' => ['array', 'value']]);

        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfiguration(discoverableLoginEnabled: false));

        $response = $this->subject->optionsAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Username is required', $body['error']);
    }

    #[Test]
    public function verifyActionWithLockout(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'lockeduser',
            'assertion' => ['id' => 'cred123'],
            'challengeToken' => 'ct_abc123',
        ]);

        $this->rateLimiterService
            ->method('checkLockout')
            ->willThrowException(new RuntimeException('Account locked', 1700000011));

        $response = $this->subject->verifyAction($request);

        self::assertSame(429, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        // UX-3: lockout reported distinctly from rate limiting.
        self::assertSame('Account temporarily locked. Please contact your administrator.', $body['error']);
        self::assertTrue($body['locked']);
    }

    #[Test]
    public function verifyActionWithNonArrayAssertion(): void
    {
        // The assertion must be a JSON object; a scalar is rejected as missing.
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => 'not-an-object',
            'challengeToken' => 'ct_abc123',
        ]);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionWithNonScalarChallengeToken(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '{"id":"cred123"}',
            'challengeToken' => ['not' => 'scalar'],
        ]);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionWithEmptyUsername(): void
    {
        $request = $this->createJsonRequest([
            'username' => '',
            'assertion' => '{"id":"cred123"}',
            'challengeToken' => 'ct_abc123',
        ]);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionWithEmptyAssertion(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '',
            'challengeToken' => 'ct_abc123',
        ]);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionWithEmptyChallengeToken(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => '{"id":"cred123"}',
            'challengeToken' => '',
        ]);

        $response = $this->subject->verifyAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeResponse($response);
        self::assertSame('Missing required fields', $body['error']);
    }

    #[Test]
    public function verifyActionLogsWarningOnFailure(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => ['bad' => 'data'],
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->method('verifyAssertionResponse')
            ->willThrowException(new RuntimeException('Verification failed', 1700000035));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Passkey assertion verification failed', self::anything());

        $response = $this->subject->verifyAction($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function optionsActionConsumesRateLimit(): void
    {
        $request = $this->createJsonRequest(['username' => 'admin']);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: \random_bytes(32),
            rpId: 'example.com',
        );
        $this->webAuthnService
            ->method('createAssertionOptions')
            ->willReturn(new AssertionOptions(
                options: $options,
                challengeToken: 'ct',
            ));
        $this->webAuthnService
            ->method('serializeRequestOptions')
            ->willReturn('{"challenge":"abc"}');

        $this->rateLimiterService
            ->expects(self::once())
            ->method('consumeRateLimit')
            ->with('login_options', self::anything());

        $this->subject->optionsAction($request);
    }

    #[Test]
    public function verifyActionConsumesRateLimit(): void
    {
        $request = $this->createJsonRequest([
            'username' => 'admin',
            'assertion' => ['id' => 'cred123', 'response' => []],
            'challengeToken' => 'ct_abc123',
        ]);
        $this->setUpFindBeUser('admin', ['uid' => 42, 'username' => 'admin']);

        $this->webAuthnService
            ->method('verifyAssertionResponse');

        $this->rateLimiterService
            ->expects(self::once())
            ->method('consumeRateLimit')
            ->with('login_verify', self::anything());

        $this->subject->verifyAction($request);
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
     * Like setUpFindBeUser(), but resolves several usernames in one test: the row is
     * chosen by the username bound through createNamedParameter().
     *
     * @param array<string, array<string, mixed>|null> $usersByUsername
     */
    private function setUpFindBeUserMap(array $usersByUsername): void
    {
        $boundUsername = '';

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturnCallback(
            /**
             * @return array<string, mixed>|false
             */
            static function () use (&$boundUsername, $usersByUsername): array|false {
                $row = \is_string($boundUsername) ? ($usersByUsername[$boundUsername] ?? null) : null;

                return $row ?? false;
            },
        );

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value) use (&$boundUsername): string {
                if (\is_string($value)) {
                    $boundUsername = $value;
                }

                return "'" . (\is_scalar($value) ? (string) $value : '') . "'";
            },
        );
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
