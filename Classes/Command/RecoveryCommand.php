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
            ->addOption('list', null, InputOption::VALUE_NONE, 'List all backend user groups and their passkey enforcement level')
            ->addOption('disable-group', null, InputOption::VALUE_REQUIRED, 'Set passkey enforcement to "off" for the given be_groups UID')
            ->addOption('disable-all', null, InputOption::VALUE_NONE, 'Set passkey enforcement to "off" for ALL backend user groups')
            ->addOption('unlock', null, InputOption::VALUE_REQUIRED, 'Reset the login lockout counters for the given username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $didSomething = false;

        if ($input->getOption('list') === true) {
            $this->listGroups($io);
            $didSomething = true;
        }

        $disableGroup = $input->getOption('disable-group');
        if (\is_string($disableGroup) && $disableGroup !== '') {
            $uid = (int) $disableGroup;
            $count = $this->disableEnforcementForGroup($uid);
            $io->success(\sprintf('Passkey enforcement set to "off" for group %d (%d row(s) updated).', $uid, $count));
            $didSomething = true;
        }

        if ($input->getOption('disable-all') === true) {
            $count = $this->disableEnforcementForAllGroups();
            $io->success(\sprintf('Passkey enforcement set to "off" for all groups (%d row(s) updated).', $count));
            $didSomething = true;
        }

        $unlock = $input->getOption('unlock');
        if (\is_string($unlock) && $unlock !== '') {
            $this->rateLimiterService->resetLockout($unlock);
            $io->success(\sprintf('Login lockout reset for user "%s".', $unlock));
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
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'title', 'passkey_enforcement', 'passkey_grace_period_days')
            ->from(self::TABLE_GROUPS)
            ->where($queryBuilder->expr()->eq('deleted', 0))
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
            ];
        }

        $io->table(['UID', 'Title', 'Enforcement', 'Grace (days)'], $tableRows);
    }

    /**
     * Set passkey_enforcement to "off" for a single group.
     *
     * @return int number of affected rows
     */
    private function disableEnforcementForGroup(int $groupUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS);

        return $connection->update(self::TABLE_GROUPS, ['passkey_enforcement' => 'off'], ['uid' => $groupUid]);
    }

    /**
     * Set passkey_enforcement to "off" for all groups.
     *
     * @return int number of affected rows
     */
    private function disableEnforcementForAllGroups(): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE_GROUPS)->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE_GROUPS)
            ->set('passkey_enforcement', 'off');

        return $queryBuilder->executeStatement();
    }
}
