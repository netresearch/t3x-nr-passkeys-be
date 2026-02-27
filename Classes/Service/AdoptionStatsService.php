<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Queries passkey adoption statistics for the admin dashboard.
 *
 * Aggregates user counts, per-group enforcement details, and identifies
 * users who have not yet registered passkeys.
 */
final class AdoptionStatsService
{
    private const TABLE_USERS = 'be_users';
    private const TABLE_CREDENTIALS = 'tx_nrpasskeysbe_credential';
    private const TABLE_GROUPS = 'be_groups';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly EnforcementService $enforcementService,
    ) {}

    /**
     * Compute the current passkey adoption statistics.
     */
    public function getStats(): AdoptionStats
    {
        $totalUsers = $this->countTotalActiveUsers();
        $usersWithPasskeys = $this->countUsersWithPasskeys();
        [$groups, $groupTitleMap] = $this->getGroupStats();
        $usersWithoutPasskeys = $this->getUsersWithoutPasskeys($groupTitleMap);

        return new AdoptionStats(
            totalUsers: $totalUsers,
            usersWithPasskeys: $usersWithPasskeys,
            groups: $groups,
            usersWithoutPasskeys: $usersWithoutPasskeys,
        );
    }

    /**
     * Count total active (non-deleted, non-disabled) backend users.
     */
    private function countTotalActiveUsers(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_USERS)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Count distinct users who have at least one active (non-deleted, non-revoked) credential.
     */
    private function countUsersWithPasskeys(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_CREDENTIALS);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->addSelectLiteral('COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('be_user') . ') AS cnt')
            ->from(self::TABLE_CREDENTIALS)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Get enforcement and adoption statistics for all active (non-deleted) groups.
     *
     * Returns both the group info list and a UID-to-title map for reuse.
     *
     * @return array{list<GroupEnforcementInfo>, array<int, string>}
     */
    private function getGroupStats(): array
    {
        $groupQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_GROUPS);
        $groupQueryBuilder->getRestrictions()->removeAll();

        $groups = $groupQueryBuilder
            ->select('uid', 'title', 'passkey_enforcement', 'passkey_grace_period_days')
            ->from(self::TABLE_GROUPS)
            ->where(
                $groupQueryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        $titleMap = [];

        foreach ($groups as $group) {
            $uidValue = $group['uid'] ?? 0;
            $groupUid = \is_numeric($uidValue) ? (int) $uidValue : 0;

            $titleValue = $group['title'] ?? '';
            $title = \is_string($titleValue) ? $titleValue : '';

            if ($groupUid > 0) {
                $titleMap[$groupUid] = $title;
            }

            $enforcementValue = $group['passkey_enforcement'] ?? 'off';
            $enforcement = \is_string($enforcementValue) ? $enforcementValue : 'off';

            $graceDaysValue = $group['passkey_grace_period_days'] ?? 0;
            $gracePeriodDays = \is_numeric($graceDaysValue) ? (int) $graceDaysValue : 0;

            $totalUsersInGroup = $this->countUsersInGroup($groupUid);
            $usersWithPasskeysInGroup = $this->countUsersWithPasskeysInGroup($groupUid);

            $result[] = new GroupEnforcementInfo(
                uid: $groupUid,
                title: $title,
                enforcement: $enforcement,
                gracePeriodDays: $gracePeriodDays,
                totalUsers: $totalUsersInGroup,
                usersWithPasskeys: $usersWithPasskeysInGroup,
            );
        }

        return [$result, $titleMap];
    }

    /**
     * Count active users in a specific group using FIND_IN_SET for TYPO3's comma-separated usergroup field.
     */
    private function countUsersInGroup(int $groupUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_USERS)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
                'FIND_IN_SET('
                    . $queryBuilder->createNamedParameter($groupUid, \Doctrine\DBAL\ParameterType::INTEGER)
                    . ', ' . $queryBuilder->quoteIdentifier('usergroup') . ')',
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Count users with active passkeys in a specific group.
     */
    private function countUsersWithPasskeysInGroup(int $groupUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->addSelectLiteral('COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier(self::TABLE_USERS . '.uid') . ') AS cnt')
            ->from(self::TABLE_USERS)
            ->join(
                self::TABLE_USERS,
                self::TABLE_CREDENTIALS,
                'c',
                $queryBuilder->expr()->eq(
                    'c.be_user',
                    $queryBuilder->quoteIdentifier(self::TABLE_USERS . '.uid'),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq(self::TABLE_USERS . '.deleted', 0),
                $queryBuilder->expr()->eq(self::TABLE_USERS . '.disable', 0),
                $queryBuilder->expr()->eq('c.deleted', 0),
                $queryBuilder->expr()->eq('c.revoked_at', 0),
                'FIND_IN_SET('
                    . $queryBuilder->createNamedParameter($groupUid, \Doctrine\DBAL\ParameterType::INTEGER)
                    . ', ' . $queryBuilder->quoteIdentifier(self::TABLE_USERS . '.usergroup') . ')',
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Get users who have no active credentials, with their grace period status.
     *
     * @param array<int, string> $groupTitleMap UID-to-title map from getGroupStats()
     *
     * @return list<UserPasskeyStatus>
     */
    private function getUsersWithoutPasskeys(array $groupTitleMap): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $subQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_CREDENTIALS);
        $subQueryBuilder->getRestrictions()->removeAll();

        $subQuery = $subQueryBuilder
            ->select('be_user')
            ->from(self::TABLE_CREDENTIALS)
            ->where(
                $subQueryBuilder->expr()->eq('deleted', 0),
                $subQueryBuilder->expr()->eq('revoked_at', 0),
            )
            ->groupBy('be_user')
            ->getSQL();

        $rows = $queryBuilder
            ->select('uid', 'username', 'realName', 'usergroup', 'passkey_grace_period_start')
            ->from(self::TABLE_USERS)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->quoteIdentifier('uid') . ' NOT IN (' . $subQuery . ')',
            )
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $uidValue = $row['uid'] ?? 0;
            $uid = \is_numeric($uidValue) ? (int) $uidValue : 0;

            $usergroupValue = $row['usergroup'] ?? '';
            $usergroup = \is_string($usergroupValue) ? $usergroupValue : '';

            $graceStartValue = $row['passkey_grace_period_start'] ?? 0;
            $graceStart = \is_numeric($graceStartValue) ? (int) $graceStartValue : 0;

            $usernameValue = $row['username'] ?? '';
            $username = \is_string($usernameValue) ? $usernameValue : '';

            $realNameValue = $row['realName'] ?? '';
            $realName = \is_string($realNameValue) ? $realNameValue : '';

            $userRow = [
                'uid' => $uid,
                'usergroup' => $usergroup,
                'passkey_grace_period_start' => $graceStart,
            ];

            $status = $this->enforcementService->getStatus($userRow);
            $groupTitles = $this->resolveGroupTitles($usergroup, $groupTitleMap);

            $result[] = new UserPasskeyStatus(
                uid: $uid,
                username: $username,
                realName: $realName,
                groups: $groupTitles,
                gracePeriodStart: $graceStart,
                gracePeriodRemainingDays: $status->gracePeriodRemainingDays(),
            );
        }

        return $result;
    }

    /**
     * Resolve a comma-separated string of group UIDs to a comma-separated string of group titles.
     *
     * @param array<int, string> $groupTitleMap
     */
    private function resolveGroupTitles(string $uidList, array $groupTitleMap): string
    {
        if ($uidList === '') {
            return '';
        }

        $uids = \array_filter(\array_map('trim', \explode(',', $uidList)));
        $titles = [];

        foreach ($uids as $uid) {
            $intUid = \is_numeric($uid) ? (int) $uid : 0;

            if ($intUid > 0 && isset($groupTitleMap[$intUid])) {
                $titles[] = $groupTitleMap[$intUid];
            }
        }

        return \implode(', ', $titles);
    }
}
