<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(RateLimiterService::class)]
final class RateLimiterServiceTest extends TestCase
{
    private FrontendInterface&MockObject $rateLimitCacheMock;
    private ExtensionConfigurationService $configService;
    private RateLimiterService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimitCacheMock = $this->createMock(FrontendInterface::class);
        $this->configService = $this->createConfigService([
            'rateLimitMaxAttempts' => 5,
            'rateLimitWindowSeconds' => 300,
            'lockoutThreshold' => 3,
            'lockoutDurationSeconds' => 900,
        ]);

        $this->subject = new RateLimiterService(
            $this->rateLimitCacheMock,
            $this->configService,
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
        // 1 failure, threshold is 3 -- should pass
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
    public function checkLockoutThrowsWhenLocked(): void
    {
        // 3 failures, threshold is 3 -- should throw (>= comparison)
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
        // 10 failures, well above threshold of 3
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
        // 2 failures (one below threshold of 3) -- should pass
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn('2');

        $this->subject->checkLockout('admin', '192.168.1.1');
        self::assertTrue(true, 'No exception was thrown');
    }

    #[Test]
    public function recordFailureIncrementsFailures(): void
    {
        // No previous failures -- get() returns false
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                '1',
                ['lockout_admin'],
                900, // lockoutDurationSeconds
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
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isType('string'),
                '3',
                ['lockout_admin'],
                900,
            );

        $this->subject->recordFailure('admin', '192.168.1.1');
    }

    #[Test]
    public function recordSuccessClearsLockout(): void
    {
        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('remove')
            ->with(self::isType('string'));

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
            ->with('lockout_admin');

        // remove() should NOT be called (it only does flushByTag path)
        $this->rateLimitCacheMock
            ->expects(self::never())
            ->method('remove');

        $this->subject->resetLockout('admin');
    }

    #[Test]
    public function rateLimitKeyIsSanitized(): void
    {
        // Using special characters that should be sanitized
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                // Key: rl_ + sanitized(endpoint) + _ + sanitized(identifier)
                // Dots, colons, dashes converted to underscores, then non-alnum/underscore removed
                self::matchesRegularExpression('/^rl_[a-zA-Z0-9_]+_[a-zA-Z0-9_]+$/'),
                '1',
                [],
                300,
            );

        $this->subject->recordAttempt('register/passkey', '192.168.1.1');
    }

    #[Test]
    public function lockoutKeyIsSanitized(): void
    {
        $this->rateLimitCacheMock
            ->method('get')
            ->willReturn(false);

        $this->rateLimitCacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                // Key: lo_ + sanitized(username) + _ + sanitized(ip)
                self::matchesRegularExpression('/^lo_[a-zA-Z0-9_]+_[a-zA-Z0-9_]+$/'),
                '1',
                // Tag: lockout_ + sanitized(username)
                self::callback(static function (array $tags): bool {
                    return \count($tags) === 1
                        && \preg_match('/^lockout_[a-zA-Z0-9_]+$/', $tags[0]) === 1;
                }),
                900,
            );

        $this->subject->recordFailure('user@example.com', '2001:db8::1');
    }
}
