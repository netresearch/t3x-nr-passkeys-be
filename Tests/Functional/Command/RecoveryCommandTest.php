<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Command;

use Netresearch\NrPasskeysBe\Command\RecoveryCommand;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The CLI recovery path against a real be_groups table: listing the
 * enforcement of every group and switching it off for one group or for all.
 */
#[CoversClass(RecoveryCommand::class)]
final class RecoveryCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce'     => ['backend' => NullBackend::class],
                    'nr_passkeys_be_ratelimit' => ['backend' => NullBackend::class],
                ],
            ],
        ],
    ];

    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_groups.csv');
        $this->tester = new CommandTester(
            new RecoveryCommand(
                GeneralUtility::makeInstance(ConnectionPool::class),
                $this->get(RateLimiterService::class),
            ),
        );
    }

    #[Test]
    public function unknownGroupReportsFailureAndLeavesExistingGroupsEnforced(): void
    {
        $db = $this
            ->getConnectionPool()
            ->getConnectionForTable('be_groups');
        $before = $db
            ->select(['passkey_suspended_until'], 'be_groups', ['uid' => 1])
            ->fetchOne();
        self::assertNotFalse($before);
        self::assertSame(1, $this->tester->execute(['--disable-group' => '9999']));
        self::assertStringContainsString('Group not found', $this->tester->getDisplay());
        self::assertSame($before, $db
            ->select(['passkey_suspended_until'], 'be_groups', ['uid' => 1])
            ->fetchOne());
    }

    #[Test]
    public function listShowsEveryGroupThatIsNotDeletedWithItsEnforcement(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->insert(
                'be_groups',
                ['uid' => 4, 'pid' => 0, 'title' => 'Deleted Group', 'passkey_enforcement' => 'enforced', 'deleted' => 1],
            );

        $exitCode = $this->tester->execute(['--list' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/\b1\s+Editors\s+required\s+14\b/', $display);
        self::assertMatchesRegularExpression('/\b2\s+Content Managers\s+encourage\s+14\b/', $display);
        self::assertMatchesRegularExpression('/\b3\s+No Enforcement\s+off\s+14\b/', $display);
        self::assertStringNotContainsString('Deleted Group', $display);
    }

    #[Test]
    public function disableGroupSwitchesOffOnlyTheGivenGroup(): void
    {
        $exitCode = $this->tester->execute(['--disable-group' => '1']);

        $db = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_groups');
        self::assertGreaterThan(
            time(),
            (int) $db
                ->select(['passkey_suspended_until'], 'be_groups', ['uid' => 1])
                ->fetchOne(),
        );
        self::assertSame(0, (int) $db
            ->select(['passkey_suspended_until'], 'be_groups', ['uid' => 2])
            ->fetchOne());
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('for group 1 (1 row(s) updated)', $this->tester->getDisplay());
        self::assertSame([1 => 'required', 2 => 'encourage', 3 => 'off'], $this->enforcementByGroup());
    }

    #[Test]
    public function disableAllRequiresConfirmationAndPreservesOriginalGroupLevels(): void
    {
        $db              = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_groups');
        $readSuspensions = static fn (): array => $db
            ->select(['uid', 'passkey_suspended_until'], 'be_groups', ['deleted' => 0], [], ['uid' => 'ASC'])
            ->fetchAllKeyValue();
        $before = $readSuspensions();
        self::assertCount(3, $before);
        self::assertSame(Command::INVALID, $this->tester->execute(['--disable-all' => true]));
        self::assertSame($before, $readSuspensions());

        $earliest = time() + 900;
        $exitCode = $this->tester->execute(['--disable-all' => true, '--confirm-all' => true]);
        $latest   = time() + 900;
        self::assertSame(Command::SUCCESS, $exitCode);
        $after = $readSuspensions();
        self::assertSame(array_keys($before), array_keys($after));

        foreach ($after as $timestamp) {
            self::assertGreaterThanOrEqual($earliest, (int) $timestamp);
            self::assertLessThanOrEqual($latest, (int) $timestamp);
        }

        self::assertStringContainsString('for all groups', $this->tester->getDisplay());
        self::assertSame([1 => 'required', 2 => 'encourage', 3 => 'off'], $this->enforcementByGroup());
    }

    /**
     * @return array<int, string>
     */
    private function enforcementByGroup(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_groups');
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'passkey_enforcement')
            ->from('be_groups')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['uid']] = (string) $row['passkey_enforcement'];
        }

        return $result;
    }
}
