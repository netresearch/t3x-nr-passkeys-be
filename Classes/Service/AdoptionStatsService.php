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
final readonly class AdoptionStatsService
{
    private const TABLE_USERS = 'be_users';

    private const TABLE_CREDENTIALS = 'tx_nrpasskeysbe_credential';

    private const TABLE_GROUPS = 'be_groups';

    /**
     * Cap on the number of passkey-less users listed on the dashboard. When more
     * exist the list is truncated and AdoptionStats::$usersWithoutPasskeysTruncated
     * is set so the UI can say so (ADMIN-4).
     */
    public const USERS_WITHOUT_PASSKEYS_LIMIT = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
        private EnforcementService $enforcementService,
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

        // The query fetches one extra row past the cap so we can tell the UI the
        // list was truncated rather than silently showing only the first N (ADMIN-4).
        $truncated = \count($usersWithoutPasskeys) > self::USERS_WITHOUT_PASSKEYS_LIMIT;
        if ($truncated) {
            $usersWithoutPasskeys = \array_slice($usersWithoutPasskeys, 0, self::USERS_WITHOUT_PASSKEYS_LIMIT);
        }

        return new AdoptionStats(
            totalUsers: $totalUsers,
            usersWithPasskeys: $usersWithPasskeys,
            groups: $groups,
            usersWithoutPasskeys: $usersWithoutPasskeys,
            usersWithoutPasskeysTruncated: $truncated,
        );
    }

    /**
     * Count total active (non-deleted, non-disabled) backend users.
     *
     * Public because the dashboard adoption widget needs this single
     * aggregate without triggering the full getStats() computation.
     */
    public function countTotalActiveUsers(): int
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
     *
     * Public because the dashboard adoption widget needs this single
     * aggregate without triggering the full getStats() computation.
     */
    public function countUsersWithPasskeys(): int
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
     * Count all active (non-deleted, non-revoked) passkey credentials that
     * belong to active (non-deleted, non-disabled) backend users.
     *
     * Unlike countUsersWithPasskeys() this counts credentials, not distinct
     * users — one user may have registered several passkeys. The join on
     * be_users keeps leftover credentials of soft-deleted or disabled users
     * out of the count. Used by the dashboard credentials widget.
     */
    public function countActiveCredentials(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_CREDENTIALS);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->count(self::TABLE_CREDENTIALS . '.uid')
            ->from(self::TABLE_CREDENTIALS)
            ->join(
                self::TABLE_CREDENTIALS,
                self::TABLE_USERS,
                'u',
                $queryBuilder->expr()->eq(
                    self::TABLE_CREDENTIALS . '.be_user',
                    $queryBuilder->quoteIdentifier('u.uid'),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq(self::TABLE_CREDENTIALS . '.deleted', 0),
                $queryBuilder->expr()->eq(self::TABLE_CREDENTIALS . '.revoked_at', 0),
                $queryBuilder->expr()->eq('u.deleted', 0),
                $queryBuilder->expr()->eq('u.disable', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Get enforcement and adoption statistics for all active (non-deleted) groups.
     *
     * Uses batch queries for user counts instead of per-group queries to avoid N+1.
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

        $groupUids = [];
        $titleMap = [];

        foreach ($groups as $group) {
            $uidValue = $group['uid'] ?? 0;
            $groupUid = \is_numeric($uidValue) ? (int) $uidValue : 0;

            if ($groupUid > 0) {
                $groupUids[] = $groupUid;
                $titleValue = $group['title'] ?? '';
                $titleMap[$groupUid] = \is_string($titleValue) ? $titleValue : '';
            }
        }

        // Batch-fetch user counts per group (avoids N+1 queries)
        $totalCountMap = $this->countUsersPerGroup($groupUids);
        $passkeyCountMap = $this->countUsersWithPasskeysPerGroup($groupUids);

        $result = [];

        foreach ($groups as $group) {
            $uidValue = $group['uid'] ?? 0;
            $groupUid = \is_numeric($uidValue) ? (int) $uidValue : 0;

            $titleValue = $group['title'] ?? '';
            $title = \is_string($titleValue) ? $titleValue : '';

            $enforcementValue = $group['passkey_enforcement'] ?? 'off';
            $enforcement = \is_string($enforcementValue) ? $enforcementValue : 'off';

            $graceDaysValue = $group['passkey_grace_period_days'] ?? 0;
            $gracePeriodDays = \is_numeric($graceDaysValue) ? (int) $graceDaysValue : 0;

            $result[] = new GroupEnforcementInfo(
                uid: $groupUid,
                title: $title,
                enforcement: $enforcement,
                gracePeriodDays: $gracePeriodDays,
                totalUsers: $totalCountMap[$groupUid] ?? 0,
                usersWithPasskeys: $passkeyCountMap[$groupUid] ?? 0,
            );
        }

        return [$result, $titleMap];
    }

    /**
     * Batch-count active users per group.
     *
     * Fetches all active users with their comma-separated usergroup field
     * and counts membership in PHP, reducing the query count to O(1).
     *
     * @param list<int> $groupUids
     *
     * @return array<int, int> Map of group UID to total user count
     */
    private function countUsersPerGroup(array $groupUids): array
    {
        if ($groupUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('usergroup')
            ->from(self::TABLE_USERS)
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->countGroupMembership($rows, $groupUids);
    }

    /**
     * Batch-count users with active passkeys per group.
     *
     * @param list<int> $groupUids
     *
     * @return array<int, int> Map of group UID to passkey-user count
     */
    private function countUsersWithPasskeysPerGroup(array $groupUids): array
    {
        if ($groupUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(self::TABLE_USERS . '.usergroup')
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
            )
            ->groupBy(self::TABLE_USERS . '.uid', self::TABLE_USERS . '.usergroup')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->countGroupMembership($rows, $groupUids);
    }

    /**
     * Count how many rows belong to each group by parsing TYPO3's comma-separated usergroup field.
     *
     * @param list<array<string, mixed>> $rows      Rows containing a 'usergroup' column
     * @param list<int>                  $groupUids Group UIDs to count for
     *
     * @return array<int, int>
     */
    private function countGroupMembership(array $rows, array $groupUids): array
    {
        $groupSet = \array_flip($groupUids);
        $counts = \array_fill_keys($groupUids, 0);

        foreach ($rows as $row) {
            $usergroupValue = $row['usergroup'] ?? '';
            $usergroup = \is_string($usergroupValue) ? $usergroupValue : '';

            if ($usergroup === '') {
                continue;
            }

            foreach (\explode(',', $usergroup) as $gid) {
                $intGid = \is_numeric($gid) ? (int) $gid : 0;

                if ($intGid > 0 && isset($groupSet[$intGid])) {
                    $counts[$intGid]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Get users who have no active credentials, with their grace period status.
     *
     * Uses LEFT JOIN instead of NOT IN subquery for better MySQL performance.
     *
     * @param array<int, string> $groupTitleMap UID-to-title map from getGroupStats()
     *
     * @return list<UserPasskeyStatus>
     */
    private function getUsersWithoutPasskeys(array $groupTitleMap): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USERS);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(
                self::TABLE_USERS . '.uid',
                self::TABLE_USERS . '.username',
                self::TABLE_USERS . '.realName',
                self::TABLE_USERS . '.usergroup',
                self::TABLE_USERS . '.passkey_grace_period_start',
                self::TABLE_USERS . '.passkey_nudge_until',
            )
            ->from(self::TABLE_USERS)
            ->leftJoin(
                self::TABLE_USERS,
                self::TABLE_CREDENTIALS,
                'c',
                (string) $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq(
                        'c.be_user',
                        $queryBuilder->quoteIdentifier(self::TABLE_USERS . '.uid'),
                    ),
                    $queryBuilder->expr()->eq('c.deleted', 0),
                    $queryBuilder->expr()->eq('c.revoked_at', 0),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq(self::TABLE_USERS . '.deleted', 0),
                $queryBuilder->expr()->eq(self::TABLE_USERS . '.disable', 0),
                $queryBuilder->expr()->isNull('c.uid'),
            )
            ->setMaxResults(self::USERS_WITHOUT_PASSKEYS_LIMIT + 1)
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

            $nudgeUntilValue = $row['passkey_nudge_until'] ?? 0;
            $nudgeUntil = \is_numeric($nudgeUntilValue) ? (int) $nudgeUntilValue : 0;

            $result[] = new UserPasskeyStatus(
                uid: $uid,
                username: $username,
                realName: $realName,
                groups: $groupTitles,
                gracePeriodStart: $graceStart,
                gracePeriodRemainingDays: $status->gracePeriodRemainingDays(),
                nudgeUntil: $nudgeUntil,
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

        $uids = \array_filter(\array_map(trim(...), \explode(',', $uidList)));
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
