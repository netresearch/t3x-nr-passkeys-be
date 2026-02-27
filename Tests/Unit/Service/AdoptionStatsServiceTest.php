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
        // Groups: 1 group with enforcement
        // Users without passkeys: 1 user

        $callIndex = 0;
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturnCallback(function (string $table) use (&$callIndex): QueryBuilder {
                $callIndex++;

                return match (true) {
                    // 1st call: countTotalActiveUsers (be_users)
                    $callIndex === 1 => $this->createCountQueryBuilder(10),
                    // 2nd call: countUsersWithPasskeys (tx_nrpasskeysbe_credential)
                    $callIndex === 2 => $this->createSelectLiteralQueryBuilder(6),
                    // 3rd call: getGroupStats - fetch groups (be_groups)
                    $callIndex === 3 => $this->createGroupFetchQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
                    ]),
                    // 4th call: countUsersInGroup (be_users)
                    $callIndex === 4 => $this->createCountQueryBuilder(8),
                    // 5th call: countUsersWithPasskeysInGroup (be_users JOIN credential)
                    $callIndex === 5 => $this->createSelectLiteralQueryBuilder(5),
                    // 6th call: getUsersWithoutPasskeys (be_users)
                    $callIndex === 6 => $this->createFetchQueryBuilder([
                        ['uid' => 42, 'username' => 'jdoe', 'realName' => 'John Doe', 'usergroup' => '1', 'passkey_grace_period_start' => 1_700_000_000],
                    ]),
                    // 7th call: getUsersWithoutPasskeys subquery (tx_nrpasskeysbe_credential)
                    $callIndex === 7 => $this->createSubQueryBuilder(),
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
                    // getGroupStats - no groups
                    $callIndex === 3 => $this->createGroupFetchQueryBuilder([]),
                    // getUsersWithoutPasskeys - no users
                    $callIndex === 4 => $this->createFetchQueryBuilder([]),
                    // subquery builder
                    $callIndex === 5 => $this->createSubQueryBuilder(),
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
                    // getGroupStats - 2 groups
                    $callIndex === 3 => $this->createGroupFetchQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
                        ['uid' => 2, 'title' => 'Admins', 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 0],
                    ]),
                    // countUsersInGroup for group 1
                    $callIndex === 4 => $this->createCountQueryBuilder(15),
                    // countUsersWithPasskeysInGroup for group 1
                    $callIndex === 5 => $this->createSelectLiteralQueryBuilder(9),
                    // countUsersInGroup for group 2
                    $callIndex === 6 => $this->createCountQueryBuilder(5),
                    // countUsersWithPasskeysInGroup for group 2
                    $callIndex === 7 => $this->createSelectLiteralQueryBuilder(5),
                    // getUsersWithoutPasskeys
                    $callIndex === 8 => $this->createFetchQueryBuilder([]),
                    $callIndex === 9 => $this->createSubQueryBuilder(),
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
                    // getGroupStats - returns groups for title map
                    $callIndex === 3 => $this->createGroupFetchQueryBuilder([
                        ['uid' => 1, 'title' => 'Editors', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                        ['uid' => 2, 'title' => 'Admins', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                        ['uid' => 3, 'title' => 'Authors', 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
                    ]),
                    // countUsersInGroup / countUsersWithPasskeysInGroup for each group
                    $callIndex === 4, $callIndex === 6, $callIndex === 8 => $this->createCountQueryBuilder(0),
                    $callIndex === 5, $callIndex === 7, $callIndex === 9 => $this->createSelectLiteralQueryBuilder(0),
                    // getUsersWithoutPasskeys
                    $callIndex === 10 => $this->createFetchQueryBuilder([
                        ['uid' => 10, 'username' => 'alice', 'realName' => 'Alice Smith', 'usergroup' => '1,2', 'passkey_grace_period_start' => 1_700_000_000],
                        ['uid' => 20, 'username' => 'bob', 'realName' => 'Bob Jones', 'usergroup' => '3', 'passkey_grace_period_start' => 0],
                    ]),
                    $callIndex === 11 => $this->createSubQueryBuilder(),
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

    /**
     * Create a QueryBuilder mock that returns a count result.
     */
    private function createCountQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('neq')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($count);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'mocked'");
        $queryBuilder->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock for SELECT with addSelectLiteral (COUNT DISTINCT).
     */
    private function createSelectLiteralQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($count);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('addSelectLiteral')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'mocked'");
        $queryBuilder->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock for fetching group rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function createGroupFetchQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('neq')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'mocked'");
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock for fetching user rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function createFetchQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'mocked'");
        $queryBuilder->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * Create a QueryBuilder mock for the subquery (credentials NOT IN).
     */
    private function createSubQueryBuilder(): QueryBuilder&MockObject
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('getSQL')->willReturn('SELECT be_user FROM tx_nrpasskeysbe_credential WHERE deleted = 0 AND revoked_at = 0 GROUP BY be_user');

        return $queryBuilder;
    }
}
