<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(AdoptionStatsService::class)]
final class AdoptionStatsServiceTest extends TestCase
{
    private AdoptionStatsService $subject;

    private ConnectionPool&MockObject $connectionPool;

    private EnforcementService&MockObject $enforcementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->enforcementService = $this->createMock(EnforcementService::class);

        $this->subject = new AdoptionStatsService(
            $this->connectionPool,
            $this->enforcementService,
        );
    }

    #[Test]
    public function getStatsReturnsCorrectCounts(): void
    {
        // Total users: 10 active
        // Users with passkeys: 6
        // Groups: 1 group (uid=1) with 8 total users, 5 with passkeys
        // Users without passkeys: 1 user

        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function (string $table) use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    // 1st: countTotalActiveUsers (be_users)
                    $callIndex === 1 => $this->createCountQueryBuilder(10),
                    // 2nd: countUsersWithPasskeys (tx_nrpasskeysbe_credential)
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(6),
                    // 3rd: getGroupStats — fetch groups (be_groups)
                    $callIndex === 3 => $this->createFetchAllQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
                    ]),
                    // 4th: countUsersPerGroup — batch fetch usergroup (be_users)
                    $callIndex === 4 => $this->createFetchAllQueryBuilder(
                        \array_fill(0, 8, ['usergroup' => '1']),
                    ),
                    // 5th: countUsersWithPasskeysPerGroup — batch fetch with JOIN (be_users)
                    $callIndex === 5 => $this->createFetchAllQueryBuilder(
                        \array_fill(0, 5, ['usergroup' => '1']),
                    ),
                    // 6th: getUsersWithoutPasskeys — LEFT JOIN (be_users)
                    $callIndex === 6 => $this->createFetchAllQueryBuilder([
                        ['uid' => 42, 'username' => 'jdoe', 'realName' => 'John Doe', 'usergroup' => '1', 'passkey_grace_period_start' => 1_700_000_000],
                    ]),
                    default => $this->createCountQueryBuilder(0),
                };
            });

        $this->enforcementService
            ->method('getStatus')
            ->willReturn(new EnforcementStatus(
                level: EnforcementLevel::Required,
                gracePeriodDays: 14,
                gracePeriodStart: 1_700_000_000,
                hasPasskeys: false,
            ));

        $stats = $this->subject->getStats();

        self::assertInstanceOf(AdoptionStats::class, $stats);
        self::assertSame(10, $stats->totalUsers);
        self::assertSame(6, $stats->usersWithPasskeys);
        self::assertSame(60.0, $stats->adoptionPercentage());
        self::assertCount(1, $stats->groups);
        self::assertSame('Editors', $stats->groups[0]->title);
        self::assertSame('required', $stats->groups[0]->enforcement);
        self::assertSame(8, $stats->groups[0]->totalUsers);
        self::assertSame(5, $stats->groups[0]->usersWithPasskeys);
    }

    #[Test]
    public function getStatsHandlesEmptyDatabase(): void
    {
        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    // countTotalActiveUsers
                    $callIndex === 1 => $this->createCountQueryBuilder(0),
                    // countUsersWithPasskeys
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(0),
                    // getGroupStats — no groups (batch counts skipped due to empty groupUids)
                    $callIndex === 3 => $this->createFetchAllQueryBuilder([]),
                    // getUsersWithoutPasskeys — no users
                    $callIndex === 4 => $this->createFetchAllQueryBuilder([]),
                    default => $this->createCountQueryBuilder(0),
                };
            });

        $stats = $this->subject->getStats();

        self::assertSame(0, $stats->totalUsers);
        self::assertSame(0, $stats->usersWithPasskeys);
        self::assertSame(0.0, $stats->adoptionPercentage());
        self::assertSame([], $stats->groups);
        self::assertSame([], $stats->usersWithoutPasskeys);
    }

    #[Test]
    public function getStatsPopulatesGroupStatsCorrectly(): void
    {
        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    // countTotalActiveUsers
                    $callIndex === 1 => $this->createCountQueryBuilder(20),
                    // countUsersWithPasskeys
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(12),
                    // getGroupStats — 2 groups
                    $callIndex === 3 => $this->createFetchAllQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
                        ['uid' => 2, 'title' => 'Admins', 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 0],
                    ]),
                    // countUsersPerGroup: 10 in group 1 only + 5 in both groups = 15 in g1, 5 in g2
                    $callIndex === 4 => $this->createFetchAllQueryBuilder([
                        ...\array_fill(0, 10, ['usergroup' => '1']),
                        ...\array_fill(0, 5, ['usergroup' => '1,2']),
                    ]),
                    // countUsersWithPasskeysPerGroup: 4 in group 1 only + 5 in both = 9 in g1, 5 in g2
                    $callIndex === 5 => $this->createFetchAllQueryBuilder([
                        ...\array_fill(0, 4, ['usergroup' => '1']),
                        ...\array_fill(0, 5, ['usergroup' => '1,2']),
                    ]),
                    // getUsersWithoutPasskeys
                    $callIndex === 6 => $this->createFetchAllQueryBuilder([]),
                    default => $this->createCountQueryBuilder(0),
                };
            });

        $stats = $this->subject->getStats();

        self::assertCount(2, $stats->groups);

        $editorsGroup = $stats->groups[0];
        self::assertSame(1, $editorsGroup->uid);
        self::assertSame('Editors', $editorsGroup->title);
        self::assertSame('required', $editorsGroup->enforcement);
        self::assertSame(14, $editorsGroup->gracePeriodDays);
        self::assertSame(15, $editorsGroup->totalUsers);
        self::assertSame(9, $editorsGroup->usersWithPasskeys);
        self::assertSame(60.0, $editorsGroup->adoptionPercentage());

        $adminsGroup = $stats->groups[1];
        self::assertSame(2, $adminsGroup->uid);
        self::assertSame('Admins', $adminsGroup->title);
        self::assertSame('enforced', $adminsGroup->enforcement);
        self::assertSame(0, $adminsGroup->gracePeriodDays);
        self::assertSame(5, $adminsGroup->totalUsers);
        self::assertSame(5, $adminsGroup->usersWithPasskeys);
        self::assertSame(100.0, $adminsGroup->adoptionPercentage());
    }

    #[Test]
    public function getStatsComputesGracePeriodRemainingDaysForUsersWithoutPasskeys(): void
    {
        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    $callIndex === 1 => $this->createCountQueryBuilder(5),
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(3),
                    // getGroupStats — returns groups for title map
                    $callIndex === 3 => $this->createFetchAllQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                        ['uid' => 2, 'title' => 'Admins', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                        ['uid' => 3, 'title' => 'Authors', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                    ]),
                    // countUsersPerGroup — some users in groups
                    $callIndex === 4 => $this->createFetchAllQueryBuilder([
                        ['usergroup' => '1,2'],
                        ['usergroup' => '3'],
                    ]),
                    // countUsersWithPasskeysPerGroup — none
                    $callIndex === 5 => $this->createFetchAllQueryBuilder([]),
                    // getUsersWithoutPasskeys
                    $callIndex === 6 => $this->createFetchAllQueryBuilder([
                        ['uid' => 10, 'username' => 'alice', 'realName' => 'Alice Smith', 'usergroup' => '1,2', 'passkey_grace_period_start' => 1_700_000_000],
                        ['uid' => 20, 'username' => 'bob', 'realName' => 'Bob Jones', 'usergroup' => '3', 'passkey_grace_period_start' => 0],
                    ]),
                    default => $this->createCountQueryBuilder(0),
                };
            });

        $enforcementCallIndex = 0;
        $this->enforcementService
            ->method('getStatus')
            ->willReturnCallback(function () use (&$enforcementCallIndex): EnforcementStatus {
                $enforcementCallIndex++;

                if ($enforcementCallIndex === 1) {
                    return new EnforcementStatus(
                        level: EnforcementLevel::Required,
                        gracePeriodDays: 14,
                        gracePeriodStart: 1_700_000_000,
                        hasPasskeys: false,
                    );
                }

                return new EnforcementStatus(
                    level: EnforcementLevel::Encourage,
                    gracePeriodDays: 30,
                    gracePeriodStart: 0,
                    hasPasskeys: false,
                );
            });

        $stats = $this->subject->getStats();

        self::assertCount(2, $stats->usersWithoutPasskeys);
        self::assertSame(10, $stats->usersWithoutPasskeys[0]->uid);
        self::assertSame('alice', $stats->usersWithoutPasskeys[0]->username);
        self::assertSame('Alice Smith', $stats->usersWithoutPasskeys[0]->realName);
        self::assertSame('Editors, Admins', $stats->usersWithoutPasskeys[0]->groups);

        self::assertSame(20, $stats->usersWithoutPasskeys[1]->uid);
        self::assertSame('bob', $stats->usersWithoutPasskeys[1]->username);
        self::assertSame('Authors', $stats->usersWithoutPasskeys[1]->groups);
        self::assertSame(30, $stats->usersWithoutPasskeys[1]->gracePeriodRemainingDays);
    }

    #[Test]
    public function getStatsSkipsUsersWithEmptyUsergroupInGroupCounts(): void
    {
        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    // countTotalActiveUsers
                    $callIndex === 1 => $this->createCountQueryBuilder(5),
                    // countUsersWithPasskeys
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(2),
                    // getGroupStats — 1 group
                    $callIndex === 3 => $this->createFetchAllQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                    ]),
                    // countUsersPerGroup — mix of users: some with groups, some with empty usergroup
                    $callIndex === 4 => $this->createFetchAllQueryBuilder([
                        ['usergroup' => '1'],
                        ['usergroup' => ''],
                        ['usergroup' => '1'],
                        ['usergroup' => ''],
                    ]),
                    // countUsersWithPasskeysPerGroup
                    $callIndex === 5 => $this->createFetchAllQueryBuilder([
                        ['usergroup' => '1'],
                        ['usergroup' => ''],
                    ]),
                    // getUsersWithoutPasskeys — user with empty usergroup
                    $callIndex === 6 => $this->createFetchAllQueryBuilder([
                        ['uid' => 10, 'username' => 'nogroup', 'realName' => 'No Group', 'usergroup' => '', 'passkey_grace_period_start' => 0],
                    ]),
                    default => $this->createCountQueryBuilder(0),
                };
            });

        $this->enforcementService
            ->method('getStatus')
            ->willReturn(new EnforcementStatus(
                level: EnforcementLevel::Off,
                gracePeriodDays: 0,
                gracePeriodStart: 0,
                hasPasskeys: false,
            ));

        $stats = $this->subject->getStats();

        // Group 1 should count only users with matching usergroup (empty strings skipped)
        self::assertCount(1, $stats->groups);
        self::assertSame(2, $stats->groups[0]->totalUsers);
        self::assertSame(1, $stats->groups[0]->usersWithPasskeys);

        // User with empty usergroup should have empty groups string
        self::assertCount(1, $stats->usersWithoutPasskeys);
        self::assertSame('nogroup', $stats->usersWithoutPasskeys[0]->username);
        self::assertSame('', $stats->usersWithoutPasskeys[0]->groups);
    }

    #[Test]
    public function countTotalActiveUsersReturnsAggregateCount(): void
    {
        $this->connectionPool
            ->expects(self::once())
            ->method('getQueryBuilderForTable')
            ->with('be_users')
            ->willReturn($this->createCountQueryBuilder(7));

        self::assertSame(7, $this->subject->countTotalActiveUsers());
    }

    #[Test]
    public function countUsersWithPasskeysReturnsAggregateCount(): void
    {
        $this->connectionPool
            ->expects(self::once())
            ->method('getQueryBuilderForTable')
            ->with('tx_nrpasskeysbe_credential')
            ->willReturn($this->createSelectLiteralQueryBuilder(4));

        self::assertSame(4, $this->subject->countUsersWithPasskeys());
    }

    #[Test]
    public function countActiveCredentialsReturnsAggregateCount(): void
    {
        $this->connectionPool
            ->expects(self::once())
            ->method('getQueryBuilderForTable')
            ->with('tx_nrpasskeysbe_credential')
            ->willReturn($this->createCountQueryBuilder(11));

        self::assertSame(11, $this->subject->countActiveCredentials());
    }

    #[Test]
    public function countActiveCredentialsReturnsZeroForNonNumericResult(): void
    {
        $queryBuilder = $this->createBaseQueryBuilder();
        $queryBuilder->method('count')->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        self::assertSame(0, $this->subject->countActiveCredentials());
    }

    /**
     * Create a QueryBuilder mock that returns a count result.
     */
    private function createCountQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createBaseQueryBuilder();
        $queryBuilder->method('count')->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($count);
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock for SELECT with addSelectLiteral (COUNT DISTINCT).
     */
    private function createSelectLiteralQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createBaseQueryBuilder();
        $queryBuilder->method('addSelectLiteral')->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($count);
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock that returns rows via fetchAllAssociative.
     *
     * Supports all query patterns: simple select, JOIN, LEFT JOIN, GROUP BY.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function createFetchAllQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createBaseQueryBuilder();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a base QueryBuilder mock with common methods.
     */
    private function createBaseQueryBuilder(): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('neq')->willReturn('1=1');
        $expressionBuilder->method('isNull')->willReturn('c.uid IS NULL');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'mocked'");
        $queryBuilder->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );

        return $queryBuilder;
    }
}
