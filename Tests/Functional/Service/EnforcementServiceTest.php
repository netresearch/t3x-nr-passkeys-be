<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Service;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the enforcement service.
 *
 * Verifies that the effective enforcement level is correctly resolved
 * from group assignments and that grace-period tracking works with
 * real database queries.
 */
#[CoversClass(EnforcementService::class)]
final class EnforcementServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'setup',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce' => [
                        'backend' => NullBackend::class,
                    ],
                    'nr_passkeys_be_ratelimit' => [
                        'backend' => NullBackend::class,
                    ],
                ],
            ],
        ],
    ];

    private EnforcementService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');
        $this->subject = $this->get(EnforcementService::class);
    }

    // --- getStatus() with real DB ---

    #[Test]
    public function getStatusReturnsOffForUserWithNoGroups(): void
    {
        // adminuser (uid=5) has usergroup=""
        $userRow = ['uid' => 5, 'usergroup' => '', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Off, $status->level);
        self::assertSame(0, $status->gracePeriodDays);
        self::assertFalse($status->hasPasskeys);
    }

    #[Test]
    public function getStatusReturnsStrictestLevelFromGroups(): void
    {
        // testuser1 (uid=1) has usergroup="1" → group 1 (required)
        $userRow = ['uid' => 1, 'usergroup' => '1', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Required, $status->level);
        self::assertSame(14, $status->gracePeriodDays);
        self::assertTrue($status->hasPasskeys); // User 1 has active credentials
    }

    #[Test]
    public function getStatusPicksStrictestLevelFromMultipleGroups(): void
    {
        // testuser2 (uid=2) has usergroup="1,2" → group 1 (required) + group 2 (encourage)
        // Strictest = required
        $userRow = ['uid' => 2, 'usergroup' => '1,2', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Required, $status->level);
        self::assertTrue($status->hasPasskeys); // User 2 has active credential
    }

    #[Test]
    public function getStatusReturnsOffForUserWithOnlyOffGroups(): void
    {
        // testuser99 (uid=99) has usergroup="3" → group 3 (off)
        $userRow = ['uid' => 99, 'usergroup' => '3', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Off, $status->level);
        self::assertFalse($status->hasPasskeys); // User 99 has no credentials
    }

    #[Test]
    public function getStatusPreservesGracePeriodStart(): void
    {
        $gracePeriodStart = 1_700_000_000;
        $userRow = ['uid' => 99, 'usergroup' => '1', 'passkey_grace_period_start' => $gracePeriodStart];
        $status = $this->subject->getStatus($userRow);

        self::assertSame($gracePeriodStart, $status->gracePeriodStart);
    }

    #[Test]
    public function getStatusDetectsUserWithPasskeys(): void
    {
        // User 1 has active credentials
        $userRow = ['uid' => 1, 'usergroup' => '1', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertTrue($status->hasPasskeys);
    }

    #[Test]
    public function getStatusDetectsUserWithoutPasskeys(): void
    {
        // User 99 has no credentials at all
        $userRow = ['uid' => 99, 'usergroup' => '1', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertFalse($status->hasPasskeys);
    }

    // --- startGracePeriod() ---

    #[Test]
    public function startGracePeriodSetsTimestampInDatabase(): void
    {
        $beforeTime = \time();

        $this->subject->startGracePeriod(99);

        $queryBuilder = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('passkey_grace_period_start')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', 99))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertGreaterThanOrEqual($beforeTime, (int) $row['passkey_grace_period_start']);
    }

    // --- requiresInterstitial + canSkip integration ---

    #[Test]
    public function requiredLevelWithoutPasskeysRequiresInterstitial(): void
    {
        // User 99 has group 3 (off), let's change it to group 1 (required)
        $userRow = ['uid' => 99, 'usergroup' => '1', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertTrue($status->requiresInterstitial());
    }

    #[Test]
    public function encourageLevelDoesNotRequireInterstitial(): void
    {
        // User 99 in group 2 (encourage)
        $userRow = ['uid' => 99, 'usergroup' => '2', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertFalse($status->requiresInterstitial());
    }

    #[Test]
    public function encourageLevelRequiresBanner(): void
    {
        // User 99 in group 2 (encourage), no passkeys
        $userRow = ['uid' => 99, 'usergroup' => '2', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertTrue($status->level->requiresBanner());
        self::assertFalse($status->hasPasskeys);
    }

    #[Test]
    public function userWithPasskeysDoesNotRequireInterstitial(): void
    {
        // User 1 has group 1 (required) AND has passkeys
        $userRow = ['uid' => 1, 'usergroup' => '1', 'passkey_grace_period_start' => 0];
        $status = $this->subject->getStatus($userRow);

        self::assertFalse($status->requiresInterstitial());
    }
}
