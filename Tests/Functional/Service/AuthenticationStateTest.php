<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Service;

use Netresearch\NrPasskeysBe\Service\ConsumedTokenStore;
use Netresearch\NrPasskeysBe\Service\DatabaseRateLimitStore;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AuthenticationStateTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['nr_passkeys_be' => ['rateLimitMaxAttempts' => 2, 'lockoutThreshold' => 2, 'lockoutUserThreshold' => 3]],
    ];

    #[Test]
    public function pruningRetainsLiveAndRecentlyExpiredClaims(): void
    {
        $pool       = $this->get(ConnectionPool::class);
        $table      = 'tx_nrpasskeysbe_consumed_token';
        $connection = $pool->getConnectionForTable($table);
        $now        = time();

        foreach (['old' => -7200, 'recent' => -60, 'active' => 120] as $key => $offset) {
            $connection->insert($table, ['token_hash' => hash('sha256', $key), 'expires_at' => $now + $offset]);
        }

        $store = new ConsumedTokenStore($pool);
        $store->pruneExpired();
        self::assertSame(0, (int) $connection->count('*', $table, ['token_hash' => hash('sha256', 'old')]));
        self::assertSame(1, (int) $connection->count('*', $table, ['token_hash' => hash('sha256', 'recent')]));
        self::assertFalse($store->claim('active', $now + 120));
    }

    #[Test]
    public function configuredBudgetRejectsTheNextAttemptButAllowsAnotherIdentifier(): void
    {
        $limiter = $this->get(RateLimiterService::class);
        $limiter->consumeRateLimit('login_options', 'first');
        $limiter->consumeRateLimit('login_options', 'first');
        $limiter->consumeRateLimit('login_options', 'second');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1700000010);
        $limiter->consumeRateLimit('login_options', 'first');
    }

    #[Test]
    public function successfulLoginAndAdminResetClearTheIntendedDatabaseCounters(): void
    {
        $limiter = $this->get(RateLimiterService::class);
        $limiter->recordFailure('editor', '192.0.2.1');
        $limiter->recordFailure('editor', '192.0.2.1');
        $limiter->recordFailure('editor', '192.0.2.2');
        $this->assertLocked($limiter, '192.0.2.3');
        $limiter->resetLockout('editor', '192.0.2.1');
        $this->assertLocked($limiter, '192.0.2.1');
        $limiter->recordSuccess('editor', '192.0.2.2');
        $limiter->checkLockout('editor', '192.0.2.1');
        $limiter->recordFailure('editor', '192.0.2.1', false);
        $limiter->recordFailure('editor', '192.0.2.1', false);
        $this->assertLocked($limiter, '192.0.2.1');
        $limiter->checkLockout('editor', '192.0.2.2');
        $limiter->resetLockout('editor');
        $limiter->checkLockout('editor', '192.0.2.1');
    }

    private function assertLocked(RateLimiterService $limiter, string $address): void
    {
        try {
            $limiter->checkLockout('editor', $address);
        } catch (RuntimeException $exception) {
            self::assertSame(1700000011, $exception->getCode());

            return;
        }

        self::fail('Expected a lockout for ' . $address);
    }

    #[Test]
    public function pruningRemovesOnlyExpiredBudgetsAndAnExpiredBudgetCanRestart(): void
    {
        $pool    = $this->get(ConnectionPool::class);
        $store   = new DatabaseRateLimitStore($pool);
        $table   = 'tx_nrpasskeysbe_rate_limit';
        $db      = $pool->getConnectionForTable($table);
        $group   = hash('sha256', 'group');
        $expired = hash('sha256', 'expired');
        $active  = hash('sha256', 'active');

        foreach ([$expired, $active] as $key) {
            self::assertTrue($store->increment($key, $group, 60, 1));
        }

        $db->update($table, ['expires_at' => time() - 1], ['key_hash' => $expired]);
        self::assertSame(0, $store->count($expired));
        self::assertTrue($store->increment($expired, $group, 60, 1));
        $db->update($table, ['expires_at' => time() - 1], ['key_hash' => $expired]);
        $store->prune();
        self::assertFalse($db
            ->select(['key_hash'], $table, ['key_hash' => $expired])
            ->fetchOne());
        self::assertSame(1, $store->count($active));
    }

    #[Test]
    public function aTicketHasOneClaimAcrossIndependentInstances(): void
    {
        $db     = $this->get(ConnectionPool::class);
        $first  = new ConsumedTokenStore($db);
        $second = new ConsumedTokenStore($db);
        self::assertTrue($first->claim('login:example', time() + 120));
        self::assertFalse($second->claim('login:example', time() + 120));
        self::assertFalse($second->claim('login:expired', time() - 1));
        self::assertTrue($second->claim('nonce:example', time() + 120));
    }

    #[Test]
    public function configuredLimiterChargesTheSharedDatabase(): void
    {
        $limiter = $this->get(RateLimiterService::class);
        $limiter->consumeRateLimit('login_options', 'configured-limiter');

        $db = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('tx_nrpasskeysbe_rate_limit');
        $key = hash('sha256', 'nr_passkeys_be_ratelimit:rl_' . hash('sha256', 'login_options|configured-limiter'));
        self::assertSame(
            1,
            (int) $db
                ->select(['attempts'], 'tx_nrpasskeysbe_rate_limit', ['key_hash' => $key])
                ->fetchOne(),
        );
    }

    #[Test]
    public function theBudgetAndResetAreSharedBetweenWorkers(): void
    {
        $db     = $this->get(ConnectionPool::class);
        $first  = new DatabaseRateLimitStore($db);
        $second = new DatabaseRateLimitStore($db);
        $key    = hash('sha256', 'account');
        $group  = hash('sha256', 'group');
        self::assertTrue($first->increment($key, $group, 30, 1));
        self::assertFalse($second->increment($key, $group, 30, 1));
        self::assertSame(1, $second->count($key));
        $first->forgetGroup($group);
        self::assertSame(0, $second->count($key));
    }
}
