<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\Exception as DatabaseException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

use function hash;
use function is_numeric;

/**
 * Per-endpoint rate limiting and per-account lockout using TYPO3 caching with atomic locking.
 */
final class RateLimiterService
{
    private FrontendInterface|DatabaseRateLimitStore $store;

    public function __construct(
        private readonly FrontendInterface $rateLimitCache,
        private readonly ExtensionConfigurationService $configService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->store = $rateLimitCache;
    }

    #[Required]
    public function injectDatabaseStore(DatabaseRateLimitStore $databaseStore): void
    {
        $this->store = $databaseStore;
    }

    /**
     * Atomically check the rate limit AND record the attempt under a single lock.
     *
     * The read, threshold comparison and increment all happen inside one critical
     * section, so concurrent requests cannot all pass the check before any of them
     * increments — the overshoot a separate check/record pair allows. This is the
     * rate-limit gate the request path should call.
     *
     * @throws DatabaseException if the shared counter store cannot be read or updated
     * @throws RuntimeException  if the rate limit is exceeded or the lock cannot be acquired
     */
    public function consumeRateLimit(string $endpoint, string $identifier): void
    {
        $config        = $this->configService->getConfiguration();
        $key           = $this->buildKey($endpoint, $identifier);
        $maxAttempts   = $config->getRateLimitMaxAttempts();
        $windowSeconds = $config->getRateLimitWindowSeconds();

        if ($this->store instanceof DatabaseRateLimitStore) {
            if (random_int(1, 128) === 1) {
                try {
                    $this->store->prune();
                } catch (DatabaseException $error) {
                    $this->logger->warning(
                        'Expired rate-limit counters could not be removed',
                        ['exception' => $error::class],
                    );
                }
            }

            if (!$this->store->increment($this->databaseKey($key), '', $windowSeconds, $maxAttempts)) {
                $this->logger->warning(
                    'Rate limit exceeded',
                    ['endpoint' => $endpoint, 'identifier' => hash('sha256', $identifier)],
                );

                throw new RuntimeException('Rate limit exceeded', 1700000010);
            }

            return;
        }

        $locker = $this->lockFactory->createLocker('ratelimit_' . $key, LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error(
                'Failed to acquire rate limit lock',
                ['operation' => 'consumeRateLimit', 'key' => $key],
            );

            throw new RuntimeException('Failed to acquire rate limit lock', 1700000012);
        }

        try {
            $current = $this->getAttemptCount($key);

            if ($current >= $maxAttempts) {
                $this->logger->warning(
                    'Rate limit exceeded',
                    ['endpoint' => $endpoint, 'identifier' => hash('sha256', $identifier)],
                );

                throw new RuntimeException('Rate limit exceeded', 1700000010);
            }

            $this->rateLimitCache->set($key, (string) ($current + 1), [], $windowSeconds);
        } finally {
            $locker->release();
        }
    }

    /**
     * Check if the lockout threshold for the given user has been exceeded.
     *
     * Checks both the per-IP+username counter and the per-username counter.
     * Either exceeding its threshold will block the attempt.
     *
     * @throws DatabaseException if the shared counter store cannot be read or updated
     * @throws RuntimeException  if user is locked out or lock cannot be acquired
     */
    public function checkLockout(string $username, string $ip): void
    {
        $config       = $this->configService->getConfiguration();
        $usernameHash = hash('sha256', $username);

        // Check per-IP+username lockout
        $ipKey     = $this->buildLockoutKey($username, $ip);
        $threshold = $config->getLockoutThreshold();

        try {
            $this->atomicCheck(
                $ipKey,
                $threshold,
                'Account temporarily locked due to too many failed attempts',
                1700000011,
            );
        } catch (RuntimeException $e) {
            if ($e->getCode() === 1700000011) {
                $this->logger->warning(
                    'Per-IP lockout triggered',
                    ['usernameHash' => $usernameHash, 'ip' => $ip, 'threshold' => $threshold],
                );
            }

            throw $e;
        }

        // Check per-username lockout (catches distributed attacks across IPs)
        $userKey       = $this->buildUserLockoutKey($username);
        $userThreshold = $config->getLockoutUserThreshold();

        try {
            $this->atomicCheck(
                $userKey,
                $userThreshold,
                'Account temporarily locked due to too many failed attempts',
                1700000011,
            );
        } catch (RuntimeException $e) {
            if ($e->getCode() === 1700000011) {
                $this->logger->warning(
                    'Per-username lockout triggered',
                    ['usernameHash' => $usernameHash, 'ip' => $ip, 'threshold' => $userThreshold],
                );
            }

            throw $e;
        }
    }

    /**
     * Record a failed authentication attempt.
     *
     * Always increments the per-IP+username counter. The cross-IP per-username
     * counter is incremented only when $countUserLockout is true.
     *
     * Passkey assertion failures pass false: a WebAuthn assertion cannot be forged
     * without the authenticator's private key, so a cross-IP per-username lockout
     * provides no real brute-force protection for passkeys but would let an
     * unauthenticated attacker lock a victim out of passkey login by spamming
     * failures for their username (account-lockout DoS). The per-IP+username
     * counter still throttles a single abusive source.
     */
    public function recordFailure(string $username, string $ip, bool $countUserLockout = true): void
    {
        $config   = $this->configService->getConfiguration();
        $duration = $config->getLockoutDurationSeconds();
        $tag      = 'lockout_' . $this->sanitize($username);

        // Increment per-IP+username counter
        $ipKey = $this->buildLockoutKey($username, $ip);
        $this->atomicIncrement($ipKey, [$tag], $duration);

        // Increment per-username counter only when requested (see method docblock)
        if ($countUserLockout) {
            $userKey = $this->buildUserLockoutKey($username);
            $this->atomicIncrement($userKey, [$tag], $duration);
        }
    }

    /**
     * Reset lockout for a specific user/IP combination.
     */
    public function resetLockout(string $username, string $ip = ''): void
    {
        if ($this->store instanceof DatabaseRateLimitStore) {
            if ($ip === '') {
                $this->store->forgetGroup($this->databaseKey('lockout_' . $this->sanitize($username)));
            } else {
                $this->store->forget($this->databaseKey($this->buildLockoutKey($username, $ip)));
            }

            return;
        }

        if ($ip !== '') {
            $key = $this->buildLockoutKey($username, $ip);
            $this->rateLimitCache->remove($key);

            return;
        }

        $this->rateLimitCache->flushByTag('lockout_' . $this->sanitize($username));
    }

    /**
     * Record a successful authentication (resets lockout counters).
     */
    public function recordSuccess(string $username, string $ip): void
    {
        $ipKey   = $this->buildLockoutKey($username, $ip);
        $userKey = $this->buildUserLockoutKey($username);

        try {
            if ($this->store instanceof DatabaseRateLimitStore) {
                $this->store->forgetPair($this->databaseKey($ipKey), $this->databaseKey($userKey));
            } else {
                $this->rateLimitCache->remove($ipKey);
                $this->rateLimitCache->remove($userKey);
            }
        } catch (Throwable $error) {
            $this->logger->error(
                'Could not reset lockout counters after successful authentication',
                ['exception' => $error::class],
            );

            return;
        }

        $this->logger->debug(
            'Lockout counters reset after successful authentication',
            ['usernameHash' => hash('sha256', $username)],
        );
    }

    /**
     * Atomically check a counter against a threshold under a lock.
     *
     * @throws RuntimeException if the threshold is reached or the lock cannot be acquired
     */
    private function atomicCheck(string $key, int $threshold, string $message, int $code): void
    {
        if ($this->store instanceof DatabaseRateLimitStore) {
            if ($this->store->count($this->databaseKey($key)) >= $threshold) {
                throw new RuntimeException($message, $code);
            }

            return;
        }

        $locker = $this->lockFactory->createLocker('ratelimit_' . $key, LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error('Failed to acquire rate limit lock', ['operation' => 'atomicCheck', 'key' => $key]);

            throw new RuntimeException('Failed to acquire rate limit lock', 1700000012);
        }

        try {
            $current = $this->getAttemptCount($key);

            if ($current >= $threshold) {
                throw new RuntimeException($message, $code);
            }
        } finally {
            $locker->release();
        }
    }

    /**
     * Atomically increment a counter under a lock.
     *
     * @param list<string> $tags
     *
     * @throws RuntimeException if the lock cannot be acquired
     */
    private function atomicIncrement(string $key, array $tags, int $ttlSeconds): void
    {
        if ($this->store instanceof DatabaseRateLimitStore) {
            $this->store->increment(
                $this->databaseKey($key),
                $this->databaseKey($tags[0] ?? ''),
                $ttlSeconds,
                PHP_INT_MAX,
            );

            return;
        }

        $locker = $this->lockFactory->createLocker('ratelimit_' . $key, LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error('Failed to acquire rate limit lock', ['operation' => 'atomicIncrement', 'key' => $key]);

            throw new RuntimeException('Failed to acquire rate limit lock', 1700000012);
        }

        try {
            $current = $this->getAttemptCount($key);
            $this->rateLimitCache->set($key, (string) ($current + 1), $tags, $ttlSeconds);
        } finally {
            $locker->release();
        }
    }

    private function getAttemptCount(string $key): int
    {
        $value = $this->rateLimitCache->get($key);

        if ($value === false) {
            return 0;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    private function databaseKey(string $key): string
    {
        return hash('sha256', $this->rateLimitCache->getIdentifier() . ':' . $key);
    }

    private function buildKey(string $endpoint, string $identifier): string
    {
        return 'rl_' . hash('sha256', $endpoint . '|' . $identifier);
    }

    private function buildLockoutKey(string $username, string $ip): string
    {
        return 'lo_' . hash('sha256', $username . '|' . $ip);
    }

    private function buildUserLockoutKey(string $username): string
    {
        return 'lou_' . hash('sha256', $username);
    }

    private function sanitize(string $value): string
    {
        return hash('sha256', $value);
    }
}
