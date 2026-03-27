<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Per-endpoint rate limiting and per-account lockout using TYPO3 caching with atomic locking.
 */
final class RateLimiterService
{
    public function __construct(
        private readonly FrontendInterface $rateLimitCache,
        private readonly ExtensionConfigurationService $configService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Check if a request is rate limited and record the attempt atomically.
     *
     * Uses a lock to prevent TOCTOU race conditions between checking the
     * current count and incrementing it.
     *
     * @throws RuntimeException if rate limit exceeded or lock cannot be acquired
     */
    public function checkRateLimit(string $endpoint, string $identifier): void
    {
        $config = $this->configService->getConfiguration();
        $key = $this->buildKey($endpoint, $identifier);
        $maxAttempts = $config->getRateLimitMaxAttempts();

        try {
            $this->atomicCheck($key, $maxAttempts, 'Rate limit exceeded', 1700000010);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 1700000010) {
                $this->logger->warning('Rate limit exceeded', [
                    'endpoint' => $endpoint,
                    'identifier' => \hash('sha256', $identifier),
                ]);
            }

            throw $e;
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

        $this->atomicIncrement($key, [], $windowSeconds);
    }

    /**
     * Check if a user is locked out from failed authentication attempts.
     *
     * Checks both the per-IP+username counter and the per-username counter.
     * Either exceeding its threshold will block the attempt.
     *
     * @throws RuntimeException if user is locked out or lock cannot be acquired
     */
    public function checkLockout(string $username, string $ip): void
    {
        $config = $this->configService->getConfiguration();
        $usernameHash = \hash('sha256', $username);

        // Check per-IP+username lockout
        $ipKey = $this->buildLockoutKey($username, $ip);
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
                $this->logger->warning('Per-IP lockout triggered', [
                    'usernameHash' => $usernameHash,
                    'ip' => $ip,
                    'threshold' => $threshold,
                ]);
            }

            throw $e;
        }

        // Check per-username lockout (catches distributed attacks across IPs)
        $userKey = $this->buildUserLockoutKey($username);
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
                $this->logger->warning('Per-username lockout triggered', [
                    'usernameHash' => $usernameHash,
                    'ip' => $ip,
                    'threshold' => $userThreshold,
                ]);
            }

            throw $e;
        }
    }

    /**
     * Record a failed authentication attempt.
     *
     * Increments both per-IP+username and per-username counters atomically.
     */
    public function recordFailure(string $username, string $ip): void
    {
        $config = $this->configService->getConfiguration();
        $duration = $config->getLockoutDurationSeconds();
        $tag = 'lockout_' . $this->sanitize($username);

        // Increment per-IP+username counter
        $ipKey = $this->buildLockoutKey($username, $ip);
        $this->atomicIncrement($ipKey, [$tag], $duration);

        // Increment per-username counter
        $userKey = $this->buildUserLockoutKey($username);
        $this->atomicIncrement($userKey, [$tag], $duration);
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
     * Record a successful authentication (resets lockout counters).
     */
    public function recordSuccess(string $username, string $ip): void
    {
        $ipKey = $this->buildLockoutKey($username, $ip);
        $this->rateLimitCache->remove($ipKey);

        $userKey = $this->buildUserLockoutKey($username);
        $this->rateLimitCache->remove($userKey);

        $this->logger->info('Lockout counters reset after successful authentication', [
            'usernameHash' => \hash('sha256', $username),
        ]);
    }

    /**
     * Atomically check a counter against a threshold under a lock.
     *
     * @throws RuntimeException if the threshold is reached or the lock cannot be acquired
     */
    private function atomicCheck(string $key, int $threshold, string $message, int $code): void
    {
        $locker = $this->lockFactory->createLocker(
            'ratelimit_' . $key,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE,
        );

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error('Failed to acquire rate limit lock', [
                'operation' => 'atomicCheck',
                'key' => $key,
            ]);

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
        $locker = $this->lockFactory->createLocker(
            'ratelimit_' . $key,
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE,
        );

        if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)) {
            $this->logger->error('Failed to acquire rate limit lock', [
                'operation' => 'atomicIncrement',
                'key' => $key,
            ]);

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

    private function buildUserLockoutKey(string $username): string
    {
        return 'lou_' . \hash('sha256', $username);
    }

    private function sanitize(string $value): string
    {
        return \hash('sha256', $value);
    }
}
