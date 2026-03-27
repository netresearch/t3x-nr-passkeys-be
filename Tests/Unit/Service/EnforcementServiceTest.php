<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(EnforcementService::class)]
final class EnforcementServiceTest extends TestCase
{
    private EnforcementService $subject;

    private ConnectionPool&MockObject $connectionPool;

    private CredentialRepository&MockObject $credentialRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->credentialRepository = $this->createMock(CredentialRepository::class);

        $this->subject = new EnforcementService(
            $this->connectionPool,
            $this->credentialRepository,
            $this->createMock(LoggerInterface::class),
        );
    }

    #[Test]
    public function getStatusReturnsOffForUserWithNoGroups(): void
    {
        $userRow = [
            'uid' => 1,
            'usergroup' => '',
        ];

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(1)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertInstanceOf(EnforcementStatus::class, $status);
        self::assertSame(EnforcementLevel::Off, $status->level);
        self::assertSame(0, $status->gracePeriodDays);
        self::assertFalse($status->hasPasskeys);
    }

    #[Test]
    public function getStatusReturnsStrictestLevelFromMultipleGroups(): void
    {
        $userRow = [
            'uid' => 5,
            'usergroup' => '1,2,3',
            'passkey_grace_period_start' => 0,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'off', 'passkey_grace_period_days' => 0],
            ['uid' => 2, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
            ['uid' => 3, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 7],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(5)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Required, $status->level);
        self::assertSame(14, $status->gracePeriodDays);
        self::assertFalse($status->hasPasskeys);
    }

    #[Test]
    public function getStatusIncludesPasskeyCount(): void
    {
        $userRow = [
            'uid' => 10,
            'usergroup' => '1',
            'passkey_grace_period_start' => 0,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 30],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(10)
            ->willReturn(2);

        $status = $this->subject->getStatus($userRow);

        self::assertTrue($status->hasPasskeys);
        self::assertSame(EnforcementLevel::Encourage, $status->level);
    }

    #[Test]
    public function getStatusUsesShortestGracePeriodAmongGroupsWithSameStrictestLevel(): void
    {
        $userRow = [
            'uid' => 7,
            'usergroup' => '1,2,3',
            'passkey_grace_period_start' => 0,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 30],
            ['uid' => 2, 'passkey_enforcement' => 'enforced', 'passkey_grace_period_days' => 7],
            ['uid' => 3, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 3],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(7)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Enforced, $status->level);
        // Enforced level always has grace period zeroed
        self::assertSame(0, $status->gracePeriodDays);
    }

    #[Test]
    public function getStatusHandlesInvalidEnforcementValuesGracefully(): void
    {
        $userRow = [
            'uid' => 8,
            'usergroup' => '1,2',
            'passkey_grace_period_start' => 0,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'bogus_value', 'passkey_grace_period_days' => 10],
            ['uid' => 2, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 5],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(8)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        // 'bogus_value' is treated as Off (severity 0), so 'encourage' (severity 1) wins
        self::assertSame(EnforcementLevel::Encourage, $status->level);
        self::assertSame(5, $status->gracePeriodDays);
    }

    #[Test]
    public function getStatusPreservesGracePeriodStartFromUserRow(): void
    {
        $userRow = [
            'uid' => 9,
            'usergroup' => '1',
            'passkey_grace_period_start' => 1700000000,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'required', 'passkey_grace_period_days' => 14],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(9)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertSame(1700000000, $status->gracePeriodStart);
    }

    #[Test]
    public function startGracePeriodUpdatesBeUsersTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'be_users',
                self::callback(static function (array $data): bool {
                    return isset($data['passkey_grace_period_start'])
                        && \is_int($data['passkey_grace_period_start'])
                        && $data['passkey_grace_period_start'] > 0;
                }),
                ['uid' => 42],
            );

        $this->connectionPool
            ->expects(self::once())
            ->method('getConnectionForTable')
            ->with('be_users')
            ->willReturn($connection);

        $this->subject->startGracePeriod(42);
    }

    #[Test]
    public function getStatusReturnsOffWhenAllGroupsHaveInvalidValues(): void
    {
        $userRow = [
            'uid' => 11,
            'usergroup' => '1,2',
            'passkey_grace_period_start' => 0,
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'invalid1', 'passkey_grace_period_days' => 10],
            ['uid' => 2, 'passkey_enforcement' => 'invalid2', 'passkey_grace_period_days' => 5],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(11)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertSame(EnforcementLevel::Off, $status->level);
    }

    #[Test]
    public function getStatusDefaultsGracePeriodStartToZeroWhenNotInUserRow(): void
    {
        $userRow = [
            'uid' => 12,
            'usergroup' => '1',
        ];

        $this->setUpGroupQuery([
            ['uid' => 1, 'passkey_enforcement' => 'encourage', 'passkey_grace_period_days' => 7],
        ]);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(12)
            ->willReturn(0);

        $status = $this->subject->getStatus($userRow);

        self::assertSame(0, $status->gracePeriodStart);
    }

    /**
     * Set up the ConnectionPool mock to return a QueryBuilder that yields the given group rows.
     *
     * @param list<array{uid: int, passkey_enforcement: string, passkey_grace_period_days: int}> $groupRows
     */
    private function setUpGroupQuery(array $groupRows): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('in')->willReturn('1=1');

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($groupRows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static fn(mixed $value): string => \is_array($value) ? \implode(',', $value) : (string) $value,
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with('be_groups')
            ->willReturn($queryBuilder);
    }
}
