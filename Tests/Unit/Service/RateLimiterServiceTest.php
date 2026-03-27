<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

#[CoversClass(RateLimiterService::class)]
final class RateLimiterServiceTest extends TestCase
{
    private FrontendInterface&MockObject $rateLimitCacheMock;
    private ExtensionConfigurationService $configService;
    private LockFactory&MockObject $lockFactoryMock;
    private LockingStrategyInterface&MockObject $lockerMock;
    private LoggerInterface&MockObject $loggerMock;
    private RateLimiterService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimitCacheMock = $this->createMock(FrontendInterface::class);
        $this->configService = $this->createConfigService([
            'rateLimitMaxAttempts' => 5,
            'rateLimitWindowSeconds' => 300,
            'lockoutThreshold' => 3,
            'lockoutUserThreshold' => 10,
            'lockoutDurationSeconds' => 900,
        ]);

        $this->lockerMock = $this->createMock(LockingStrategyInterface::class);
        $this->lockerMock->method('acquire')->willReturn(true);

        $this->lockFactoryMock = $this->createMock(LockFactory::class);
        $this->lockFactoryMock->method('createLocker')->willReturn($this->lockerMock);

        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subject = new RateLimiterService(
            $this->rateLimitCacheMock,
            $this->configService,
            $this->lockFactoryMock,
            $this->loggerMock,
        );
    }

    /**
     * Create a real ExtensionConfigurationService with mocked TYPO3 ExtensionConfiguration.
     *
     * @param array<string, mixed> $settings
     */
    private function createConfigService(array $settings = []): ExtensionConfigurationService
    {
        $typo3ExtConfig = $this->createMock(ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->with('nr_passkeys_be')
            ->willReturn($settings);

        return new ExtensionConfigurationService($typo3ExtConfig);
    }

    // --- Rate Limit Tests ---

    #[Test]
    public function checkRateLimitPassesUnderLimit(): void
    {
        // Current count is 2, limit is 5 -- should pass
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('2');

        // Should not throw
        $this->subject->checkRateLimit('register', '192.168.1.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function checkRateLimitPassesWhenNoPreviousAttempts(): void
    {
        // No cache entry exists -- get() returns false
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->subject->checkRateLimit('register', '10.0.0.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function checkRateLimitThrowsWhenExceeded(): void
    {
        // Current count is 5, limit is 5 -- should throw (>= comparison)
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('5');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000010);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->subject->checkRateLimit('register', '192.168.1.1');
    }

    #[Test]
    public function checkRateLimitThrowsWhenOverLimit(): void
    {
        // Current count is 10, well above the limit of 5
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('10');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000010);

        $this->subject->checkRateLimit('login', '10.0.0.1');
    }

    #[Test]
    public function checkRateLimitPassesAtBoundary(): void
    {
        // Current count is 4 (one below limit of 5) -- should pass
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('4');

        $this->subject->checkRateLimit('register', '192.168.1.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function recordAttemptIncrementsCounter(): void
    {
        // No previous attempts -- get() returns false
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                '1',
                [],
                300, // rateLimitWindowSeconds
            );

        $this->subject->recordAttempt('register', '192.168.1.1');
    }

    #[Test]
    public function recordAttemptIncrementsExistingCounter(): void
    {
        // Previous count is 3
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('3');

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                '4',
                [],
                300,
            );

        $this->subject->recordAttempt('register', '192.168.1.1');
    }

    // --- Lockout Tests ---

    #[Test]
    public function checkLockoutPassesUnderThreshold(): void
    {
        // 1 failure, threshold is 3 (per-IP) and 10 (per-user) -- should pass
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('1');

        $this->subject->checkLockout('admin', '192.168.1.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function checkLockoutPassesWhenNoFailures(): void
    {
        // No cache entry -- get() returns false
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->subject->checkLockout('admin', '10.0.0.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function checkLockoutThrowsWhenPerIpLocked(): void
    {
        // 3 failures, per-IP threshold is 3 -- should throw (>= comparison)
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000011);
        $this->expectExceptionMessage('Account temporarily locked due to too many failed attempts');

        $this->subject->checkLockout('admin', '192.168.1.1');
    }

    #[Test]
    public function checkLockoutThrowsWhenOverThreshold(): void
    {
        // 10 failures, well above per-IP threshold of 3
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('10');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000011);

        $this->subject->checkLockout('editor', '172.16.0.1');
    }

    #[Test]
    public function checkLockoutPassesAtBoundary(): void
    {
        // 2 failures (one below per-IP threshold of 3) -- should pass
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('2');

        $this->subject->checkLockout('admin', '192.168.1.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function checkLockoutThrowsWhenPerUserThresholdExceeded(): void
    {
        $ipKey = 'lo_' . \hash('sha256', 'admin|192.168.1.1');
        $userKey = 'lou_' . \hash('sha256', 'admin');

        // Per-IP counter is under threshold (2 < 3) but per-user counter is at threshold (10 >= 10)
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturnCallback(static function (string $key) use ($ipKey, $userKey): string|false {
                if ($key === $ipKey) {
                    return '2';
                }
                if ($key === $userKey) {
                    return '10';
                }
                return false;
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000011);

        $this->subject->checkLockout('admin', '192.168.1.1');
    }

    #[Test]
    public function recordFailureIncrementsBothCounters(): void
    {
        // No previous failures -- get() returns false
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $expectedTag = 'lockout_' . \hash('sha256', 'admin');

        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('set')
            ->with(
                self::isType('string'),
                '1',
                [$expectedTag],
                900,
            );

        $this->subject->recordFailure('admin', '192.168.1.1');
    }

    #[Test]
    public function recordFailureIncrementsExistingFailures(): void
    {
        // Previous failures: 2
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('2');

        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('set')
            ->with(
                self::isType('string'),
                '3',
                self::callback(static function (array $tags): bool {
                    return \count($tags) === 1 && \str_starts_with($tags[0], 'lockout_');
                }),
                900,
            );

        $this->subject->recordFailure('admin', '192.168.1.1');
    }

    #[Test]
    public function recordSuccessClearsBothLockoutKeys(): void
    {
        $ipKey = 'lo_' . \hash('sha256', 'admin|192.168.1.1');
        $userKey = 'lou_' . \hash('sha256', 'admin');

        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('remove')
            ->with(self::callback(static function (string $key) use ($ipKey, $userKey): bool {
                return $key === $ipKey || $key === $userKey;
            }));

        $this->subject->recordSuccess('admin', '192.168.1.1');
    }

    #[Test]
    public function resetLockoutClearsCacheWithIp(): void
    {
        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('remove')
            ->with(self::isType('string'));

        // flushByTag should NOT be called when IP is provided
        $this->rateLimitCacheMock
            ->expects(self::never())
            ->method('flushByTag');

        $this->subject->resetLockout('admin', '192.168.1.1');
    }

    #[Test]
    public function resetLockoutFlushesTagWithoutIp(): void
    {
        // When no IP is provided, it flushes by tag for the user
        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('flushByTag')
            ->with('lockout_' . \hash('sha256', 'admin'));

        // remove() should NOT be called (it only does flushByTag path)
        $this->rateLimitCacheMock
            ->expects(self::never())
            ->method('remove');

        $this->subject->resetLockout('admin');
    }

    #[Test]
    public function rateLimitKeyUsesHash(): void
    {
        // Keys now use sha256 hash for collision-free sanitization
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $expectedKey = 'rl_' . \hash('sha256', 'register/passkey|192.168.1.1');

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                $expectedKey,
                '1',
                [],
                300,
            );

        $this->subject->recordAttempt('register/passkey', '192.168.1.1');
    }

    #[Test]
    public function lockoutKeyUsesHash(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $expectedIpKey = 'lo_' . \hash('sha256', 'user@example.com|2001:db8::1');
        $expectedUserKey = 'lou_' . \hash('sha256', 'user@example.com');
        $expectedTag = 'lockout_' . \hash('sha256', 'user@example.com');

        $setCalls = [];
        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('set')
            ->willReturnCallback(static function (string $key) use (&$setCalls): void {
                $setCalls[] = $key;
            });

        $this->subject->recordFailure('user@example.com', '2001:db8::1');

        self::assertContains($expectedIpKey, $setCalls);
        self::assertContains($expectedUserKey, $setCalls);
    }

    #[Test]
    public function resetLockoutWithEmptyStringIp(): void
    {
        // When IP is empty string, it should use flushByTag path
        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('flushByTag')
            ->with('lockout_' . \hash('sha256', 'testuser'));

        $this->rateLimitCacheMock
            ->expects(self::never())
            ->method('remove');

        $this->subject->resetLockout('testuser', '');
    }

    #[Test]
    public function recordSuccessClearsCorrectKeys(): void
    {
        $expectedIpKey = 'lo_' . \hash('sha256', 'admin|192.168.1.1');
        $expectedUserKey = 'lou_' . \hash('sha256', 'admin');

        $removedKeys = [];
        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('remove')
            ->willReturnCallback(static function (string $key) use (&$removedKeys): bool {
                $removedKeys[] = $key;
                return true;
            });

        $this->subject->recordSuccess('admin', '192.168.1.1');

        self::assertContains($expectedIpKey, $removedKeys);
        self::assertContains($expectedUserKey, $removedKeys);
    }

    #[Test]
    public function hashHandlesSpecialCharacters(): void
    {
        // Test with special characters in both endpoint and identifier
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $expectedKey = 'rl_' . \hash('sha256', 'login:options/v2|::1');

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                $expectedKey,
                '1',
                [],
                300,
            );

        $this->subject->recordAttempt('login:options/v2', '::1');
    }

    #[Test]
    public function recordFailureUsesCacheTagsForLockout(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('1');

        $this->rateLimitCacheMock
            ->expects(self::exactly(2))
            ->method('set')
            ->with(
                self::isType('string'),
                '2',
                self::callback(static function (array $tags): bool {
                    return \count($tags) === 1 && \str_starts_with($tags[0], 'lockout_');
                }),
                900,
            );

        $this->subject->recordFailure('testuser', '10.0.0.1');
    }

    #[Test]
    public function checkRateLimitCountsFromZeroWhenNoPreviousData(): void
    {
        // No cache data - get returns false - count should be 0, which is < 5
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        // Should pass without exception
        $this->subject->checkRateLimit('test_endpoint', '127.0.0.1');
        self::assertTrue(true);
    }

    #[Test]
    public function checkLockoutCountsFromZeroWhenNoPreviousData(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->subject->checkLockout('newuser', '127.0.0.1');
        self::assertTrue(true);
    }

    // --- Locking Tests ---

    #[Test]
    public function checkRateLimitAcquiresAndReleasesLock(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('2');

        $this->lockerMock
            ->expects(self::atLeastOnce())
            ->method('acquire')
            ->willReturn(true);

        $this->lockerMock
            ->expects(self::atLeastOnce())
            ->method('release');

        $this->subject->checkRateLimit('register', '192.168.1.1');
    }

    #[Test]
    public function checkRateLimitThrowsWhenLockCannotBeAcquired(): void
    {
        $failingLocker = $this->createMock(LockingStrategyInterface::class);
        $failingLocker->method('acquire')->willReturn(false);

        $failingLockFactory = $this->createMock(LockFactory::class);
        $failingLockFactory->method('createLocker')->willReturn($failingLocker);

        $subject = new RateLimiterService(
            $this->rateLimitCacheMock,
            $this->configService,
            $failingLockFactory,
            $this->loggerMock,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000012);

        $subject->checkRateLimit('register', '192.168.1.1');
    }

    #[Test]
    public function recordAttemptAcquiresAndReleasesLock(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->lockerMock
            ->expects(self::atLeastOnce())
            ->method('acquire')
            ->willReturn(true);

        $this->lockerMock
            ->expects(self::atLeastOnce())
            ->method('release');

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set');

        $this->subject->recordAttempt('register', '192.168.1.1');
    }

    #[Test]
    public function checkLockoutAcquiresLocksForBothCounters(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('1');

        // Two locks: one for per-IP, one for per-user check
        $this->lockFactoryMock
            ->expects(self::exactly(2))
            ->method('createLocker')
            ->willReturn($this->lockerMock);

        $this->subject->checkLockout('admin', '192.168.1.1');
    }

    #[Test]
    public function recordFailureReleasesLockEvenOnException(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->rateLimitCacheMock
            ->method('set')
            ->willThrowException(new RuntimeException('Cache write failed'));

        // Lock should still be released via finally block
        $this->lockerMock
            ->expects(self::atLeastOnce())
            ->method('release');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cache write failed');

        $this->subject->recordFailure('admin', '192.168.1.1');
    }
}
