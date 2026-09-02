<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the adoption statistics service.
 *
 * Verifies that user counts, group membership, and adoption metrics
 * are correctly computed against a real database.
 */
#[CoversClass(AdoptionStatsService::class)]
final class AdoptionStatsServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce' => ['backend' => NullBackend::class],
                    'nr_passkeys_be_ratelimit' => ['backend' => NullBackend::class],
                ],
            ],
        ],
    ];

    private AdoptionStatsService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');
        $this->subject = $this->get(AdoptionStatsService::class);
    }

    #[Test]
    public function getStatsTotalUsersCountsActiveUsersOnly(): void
    {
        $stats = $this->subject->getStats();

        // be_users.csv has 5 rows: uid 1,2,5,42,99 — all active (deleted=0, disable=0)
        self::assertSame(5, $stats->totalUsers);
    }

    #[Test]
    public function getStatsUsersWithPasskeysCountsDistinctUsersWithActiveCredentials(): void
    {
        $stats = $this->subject->getStats();

        // credential.csv: user 1 has 2 active (uid 1,2) + 1 revoked + 1 deleted
        // user 2 has 1 active (uid 5)
        // Distinct users with active (non-revoked, non-deleted) credentials: 2
        self::assertSame(2, $stats->usersWithPasskeys);
    }

    #[Test]
    public function getStatsAdoptionPercentageIsCorrect(): void
    {
        $stats = $this->subject->getStats();

        // 2 out of 5 users = 40%
        self::assertEqualsWithDelta(40.0, $stats->adoptionPercentage(), 0.1);
    }

    #[Test]
    public function getStatsGroupsContainAllNonDeletedGroups(): void
    {
        $stats = $this->subject->getStats();

        // be_groups.csv has 3 groups: Editors (required), Content Managers (encourage), No Enforcement (off)
        self::assertCount(3, $stats->groups);
        $groupTitles = \array_map(static fn(GroupEnforcementInfo $g): string => $g->title, $stats->groups);
        self::assertContains('Editors', $groupTitles);
        self::assertContains('Content Managers', $groupTitles);
        self::assertContains('No Enforcement', $groupTitles);
    }

    #[Test]
    public function getStatsGroupUserCountsMatchUserGroupAssignments(): void
    {
        $stats = $this->subject->getStats();

        // be_users.csv: user 1 → group "1", user 2 → groups "1,2", user 99 → group "3"
        // admin users 5,42 → no groups
        $groupMap = [];

        foreach ($stats->groups as $group) {
            $groupMap[$group->uid] = $group;
        }

        // Group 1 (Editors): users 1 and 2
        self::assertSame(2, $groupMap[1]->totalUsers);

        // Group 2 (Content Managers): user 2 only
        self::assertSame(1, $groupMap[2]->totalUsers);

        // Group 3 (No Enforcement): user 99 only
        self::assertSame(1, $groupMap[3]->totalUsers);
    }

    #[Test]
    public function getStatsGroupUsersWithPasskeysCountsCorrectly(): void
    {
        $stats = $this->subject->getStats();
        $groupMap = [];

        foreach ($stats->groups as $group) {
            $groupMap[$group->uid] = $group;
        }

        // Group 1 (Editors): user 1 has passkeys, user 2 has passkeys → 2
        self::assertSame(2, $groupMap[1]->usersWithPasskeys);

        // Group 2 (Content Managers): user 2 has passkeys → 1
        self::assertSame(1, $groupMap[2]->usersWithPasskeys);

        // Group 3 (No Enforcement): user 99 has no passkeys → 0
        self::assertSame(0, $groupMap[3]->usersWithPasskeys);
    }

    #[Test]
    public function getStatsGroupAdoptionPercentageIsCorrect(): void
    {
        $stats = $this->subject->getStats();
        $groupMap = [];

        foreach ($stats->groups as $group) {
            $groupMap[$group->uid] = $group;
        }

        // Group 1: 2/2 = 100%
        self::assertEqualsWithDelta(100.0, $groupMap[1]->adoptionPercentage(), 0.1);

        // Group 2: 1/1 = 100%
        self::assertEqualsWithDelta(100.0, $groupMap[2]->adoptionPercentage(), 0.1);

        // Group 3: 0/1 = 0%
        self::assertEqualsWithDelta(0.0, $groupMap[3]->adoptionPercentage(), 0.1);
    }

    #[Test]
    public function getStatsUsersWithoutPasskeysListsUsersWithNoActiveCredentials(): void
    {
        $stats = $this->subject->getStats();

        // Users without active credentials: uid 5 (adminuser), 42 (revokeadmin), 99 (testuser99)
        // (Users 1 and 2 both have active credentials)
        self::assertCount(3, $stats->usersWithoutPasskeys);
        $usernames = \array_map(static fn(UserPasskeyStatus $u): string => $u->username, $stats->usersWithoutPasskeys);
        self::assertContains('adminuser', $usernames);
        self::assertContains('revokeadmin', $usernames);
        self::assertContains('testuser99', $usernames);
    }

    #[Test]
    public function getStatsUsersWithoutPasskeysHaveCorrectGroupTitles(): void
    {
        $stats = $this->subject->getStats();
        $userMap = [];

        foreach ($stats->usersWithoutPasskeys as $user) {
            $userMap[$user->username] = $user;
        }

        // adminuser (uid=5) has no groups → empty group titles
        self::assertSame('', $userMap['adminuser']->groups);

        // testuser99 (uid=99) has group 3 → "No Enforcement"
        self::assertSame('No Enforcement', $userMap['testuser99']->groups);
    }

    #[Test]
    public function getStatsAdminUsersWithoutGroupsHaveOffEnforcementLevel(): void
    {
        $stats = $this->subject->getStats();
        $userMap = [];

        foreach ($stats->usersWithoutPasskeys as $user) {
            $userMap[$user->username] = $user;
        }

        // Admin users without groups should NOT have any grace period
        // (enforcement is Off for users without groups)
        self::assertSame(0, $userMap['adminuser']->gracePeriodRemainingDays);
    }

    #[Test]
    public function getStatsExcludesDeletedUsers(): void
    {
        // Add a deleted user to verify it's excluded
        $connection = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('be_users');
        $connection->insert(
            'be_users',
            [
                'uid' => 100,
                'pid' => 0,
                'username' => 'deleteduser',
                'password' => 'pass',
                'admin' => 0,
                'disable' => 0,
                'deleted' => 1,
                'usergroup' => '1',
            ],
        );
        $stats = $this->subject->getStats();

        // Deleted user should NOT be counted
        self::assertSame(5, $stats->totalUsers);
        $usernames = \array_map(static fn(UserPasskeyStatus $u): string => $u->username, $stats->usersWithoutPasskeys);
        self::assertNotContains('deleteduser', $usernames);
    }

    #[Test]
    public function getStatsExcludesDisabledUsers(): void
    {
        // Add a disabled user
        $connection = $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('be_users');
        $connection->insert(
            'be_users',
            [
                'uid' => 101,
                'pid' => 0,
                'username' => 'disableduser',
                'password' => 'pass',
                'admin' => 0,
                'disable' => 1,
                'deleted' => 0,
                'usergroup' => '1',
            ],
        );
        $stats = $this->subject->getStats();

        // Disabled user should NOT be counted
        self::assertSame(5, $stats->totalUsers);
    }

    #[Test]
    public function countActiveCredentialsCountsActiveCredentialsOfActiveUsersOnly(): void
    {
        // credential.csv: user 1 has 2 active + 1 revoked + 1 deleted,
        // user 2 has 1 active => 3 active credentials of active users
        self::assertSame(3, $this->subject->countActiveCredentials());

        // A leftover active credential of a disabled user must not count
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool
            ->getConnectionForTable('be_users')
            ->insert(
                'be_users',
                [
                    'uid' => 102,
                    'pid' => 0,
                    'username' => 'disabledwithpasskey',
                    'password' => 'pass',
                    'admin' => 0,
                    'disable' => 1,
                    'deleted' => 0,
                    'usergroup' => '1',
                ],
            );
        $connectionPool
            ->getConnectionForTable('tx_nrpasskeysbe_credential')
            ->insert(
                'tx_nrpasskeysbe_credential',
                [
                    'uid' => 100,
                    'pid' => 0,
                    'be_user' => 102,
                    'credential_id' => 'credential-id-disabled-user',
                    'public_key_cose' => 'public-key-cose-data-disabled',
                    'sign_count' => 0,
                    'user_handle' => 'user-handle-disabled',
                    'aaguid' => '00000000-0000-0000-0000-000000000005',
                    'transports' => '["usb"]',
                    'label' => 'Disabled User Credential',
                    'created_at' => 1700000000,
                    'last_used_at' => 0,
                    'revoked_at' => 0,
                    'revoked_by' => 0,
                    'deleted' => 0,
                ],
            );
        self::assertSame(3, $this->subject->countActiveCredentials());
    }

    #[Test]
    public function countAggregatesMatchGetStats(): void
    {
        $stats = $this->subject->getStats();
        self::assertSame($stats->totalUsers, $this->subject->countTotalActiveUsers());
        self::assertSame($stats->usersWithPasskeys, $this->subject->countUsersWithPasskeys());
    }
}
