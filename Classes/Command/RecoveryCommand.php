<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Command;

use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Utility\TypeCastTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_string;
use function sprintf;

/**
 * Emergency, out-of-band recovery from the CLI.
 *
 * Covers the lockout scenarios that have no in-backend escape: e.g. a group set to
 * Enforced where every member (including admins) lost access to their authenticator,
 * or an account locked out by failed attempts. Run on the server, no backend login
 * required.
 */
#[AsCommand(
    name: 'passkeys:recovery',
    description: 'Emergency recovery: list/disable passkey enforcement and reset login lockouts from the CLI.',
)]
final class RecoveryCommand extends Command
{
    use TypeCastTrait;

    private const TABLE_GROUPS = 'be_groups';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RateLimiterService $rateLimiterService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'list',
                null,
                InputOption::VALUE_NONE,
                'List all backend user groups and their passkey enforcement level',
            )
            ->addOption('disable-group', null, InputOption::VALUE_REQUIRED, 'Suspend enforcement for 15 minutes for the given group UID')
            ->addOption('confirm-all', null, InputOption::VALUE_NONE, 'Confirm a temporary suspension for every active group')
            ->addOption(
                'disable-all',
                null,
                InputOption::VALUE_NONE,
                'Suspend passkey group enforcement for 15 minutes (requires --confirm-all)',
            )
            ->addOption('unlock', null, InputOption::VALUE_REQUIRED, 'Reset the login lockout counters for the given username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $didSomething = false;

        $group = $input->getOption('disable-group');
        $all   = $input->getOption('disable-all') === true;

        if ($group !== null && (!is_string($group) || !ctype_digit($group) || (int) $group < 1 || $all) || $all && $input->getOption('confirm-all') !== true) {
            $io->error('Use a single positive group UID, or --disable-all --confirm-all for a 15-minute suspension.');

            return Command::INVALID;
        }

        if ($input->getOption('list') === true) {
            $this->listGroups($io);
            $didSomething = true;
        }

        $disableGroup = $input->getOption('disable-group');

        if (is_string($disableGroup) && $disableGroup !== '') {
            $uid   = (int) $disableGroup;
            $until = time() + 900;
            $count = $this->disableEnforcementForGroup($uid, $until);

            if ($count === 0) {
                $io->error('Group not found or already suspended until the same time.');

                return Command::FAILURE;
            }

            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning(
                    'Emergency passkey group suspension',
                    ['group' => $uid, 'expiresAt' => $until],
                );
            $io->success(
                sprintf('Passkey enforcement suspended for group %d (%d row(s) updated) for 15 minutes.', $uid, $count),
            );
            $didSomething = true;
        }

        if ($input->getOption('disable-all') === true) {
            $until = time() + 900;
            $count = $this->disableEnforcementForAllGroups($until);
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning(
                    'Emergency passkey enforcement suspension for all active groups',
                    ['expiresAt' => $until, 'groups' => $count],
                );
            $io->success(
                sprintf(
                    'Passkey group enforcement suspended for all groups until %s. The global password policy still applies.',
                    gmdate('c', $until),
                ),
            );
            $didSomething = true;
        }

        $unlock = $input->getOption('unlock');

        if (is_string($unlock) && $unlock !== '') {
            $this->rateLimiterService->resetLockout($unlock);
            $io->success(sprintf('Login lockout reset for user "%s".', $unlock));
            $didSomething = true;
        }

        if (!$didSomething) {
            $io->note('Nothing to do. Use --list, --disable-group=UID, --disable-all or --unlock=USERNAME.');

            return Command::INVALID;
        }

        return Command::SUCCESS;
    }

    private function listGroups(SymfonyStyle $io): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_GROUPS);
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $rows = $queryBuilder
            ->select(
                'uid',
                'title',
                'passkey_enforcement',
                'passkey_grace_period_days',
                'passkey_suspended_until',
            )
            ->from(self::TABLE_GROUPS)
            ->where($queryBuilder
                    ->expr()
                    ->eq('deleted', 0))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $tableRows = [];

        foreach ($rows as $row) {
            $tableRows[] = [
                (string) self::intVal($row['uid'] ?? null),
                self::stringVal($row['title'] ?? null),
                self::stringVal($row['passkey_enforcement'] ?? null, 'off'),
                (string) self::intVal($row['passkey_grace_period_days'] ?? null),
                self::intVal($row['passkey_suspended_until'] ?? 0) > time() ? gmdate('c', self::intVal($row['passkey_suspended_until'])) : '-',
            ];
        }

        $io->table(['UID', 'Title', 'Enforcement', 'Grace (days)', 'Suspended until (UTC)'], $tableRows);
    }

    /**
     * Set passkey_enforcement to "off" for a single group.
     *
     * @return int number of affected rows
     */
    private function disableEnforcementForGroup(int $groupUid, int $until): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS);

        return $connection->update(
            self::TABLE_GROUPS,
            ['passkey_suspended_until' => $until],
            ['uid' => $groupUid, 'deleted' => 0],
        );
    }

    /**
     * Set passkey_enforcement to "off" for all groups.
     *
     * @return int number of affected rows
     */
    private function disableEnforcementForAllGroups(int $until): int
    {
        $queryBuilder = $this->connectionPool
            ->getConnectionForTable(self::TABLE_GROUPS)
            ->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE_GROUPS)
            ->set('passkey_suspended_until', $until)
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
            );

        return $queryBuilder->executeStatement();
    }
}
