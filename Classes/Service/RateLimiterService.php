<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class RateLimiterService
{
    public function __construct(
        private readonly FrontendInterface $rateLimitCache,
        private readonly ExtensionConfigurationService $configService,
    ) {}

    /**
     * Check if a request is rate limited.
     *
     * @throws RuntimeException if rate limit exceeded
     */
    public function checkRateLimit(string $endpoint, string $identifier): void
    {
        $config = $this->configService->getConfiguration();
        $key = $this->buildKey($endpoint, $identifier);
        $maxAttempts = $config->getRateLimitMaxAttempts();

        $current = $this->getAttemptCount($key);
        if ($current >= $maxAttempts) {
            throw new RuntimeException(
                'Rate limit exceeded',
                1700000010,
            );
        }
    }

    /**
     * Record a request attempt.
     */
    public function recordAttempt(string $endpoint, string $identifier): void
    {
        $config = $this->configService->getConfiguration();
        $key = $this->buildKey($endpoint, $identifier);
        $windowSeconds = $config->getRateLimitWindowSeconds();

        $current = $this->getAttemptCount($key);
        $this->rateLimitCache->set($key, (string) ($current + 1), [], $windowSeconds);
    }

    /**
     * Check if a user is locked out from failed authentication attempts.
     *
     * @throws RuntimeException if user is locked out
     */
    public function checkLockout(string $username, string $ip): void
    {
        $config = $this->configService->getConfiguration();
        $key = $this->buildLockoutKey($username, $ip);
        $threshold = $config->getLockoutThreshold();

        $failures = $this->getAttemptCount($key);
        if ($failures >= $threshold) {
            throw new RuntimeException(
                'Account temporarily locked due to too many failed attempts',
                1700000011,
            );
        }
    }

    /**
     * Record a failed authentication attempt.
     */
    public function recordFailure(string $username, string $ip): void
    {
        $config = $this->configService->getConfiguration();
        $key = $this->buildLockoutKey($username, $ip);
        $duration = $config->getLockoutDurationSeconds();

        $current = $this->getAttemptCount($key);
        $this->rateLimitCache->set(
            $key,
            (string) ($current + 1),
            ['lockout_' . $this->sanitize($username)],
            $duration,
        );
    }

    /**
     * Reset lockout for a specific user/IP combination.
     */
    public function resetLockout(string $username, string $ip = ''): void
    {
        if ($ip !== '') {
            $key = $this->buildLockoutKey($username, $ip);
            $this->rateLimitCache->remove($key);
            return;
        }

        $this->rateLimitCache->flushByTag('lockout_' . $this->sanitize($username));
    }

    /**
     * Record a successful authentication (resets lockout counter).
     */
    public function recordSuccess(string $username, string $ip): void
    {
        $key = $this->buildLockoutKey($username, $ip);
        $this->rateLimitCache->remove($key);
    }

    private function getAttemptCount(string $key): int
    {
        $value = $this->rateLimitCache->get($key);
        if ($value === false) {
            return 0;
        }

        return \is_numeric($value) ? (int) $value : 0;
    }

    private function buildKey(string $endpoint, string $identifier): string
    {
        return 'rl_' . \hash('sha256', $endpoint . '|' . $identifier);
    }

    private function buildLockoutKey(string $username, string $ip): string
    {
        return 'lo_' . \hash('sha256', $username . '|' . $ip);
    }

    private function sanitize(string $value): string
    {
        return \hash('sha256', $value);
    }
}
