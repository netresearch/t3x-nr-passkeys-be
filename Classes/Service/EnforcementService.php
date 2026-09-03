<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\ArrayParameterType;
use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the effective passkey enforcement status for a backend user.
 *
 * Evaluates all assigned backend user groups, picks the strictest enforcement
 * level, and combines it with grace-period and passkey-count information
 * into an EnforcementStatus snapshot.
 */
final readonly class EnforcementService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private CredentialRepository $credentialRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Determine the effective enforcement status for a backend user.
     *
     * @param array<string, mixed> $userRow Backend user record (must contain 'uid' and 'usergroup')
     */
    public function getStatus(array $userRow): EnforcementStatus
    {
        $uidValue = $userRow['uid'] ?? 0;
        $beUserUid = \is_numeric($uidValue) ? (int) $uidValue : 0;
        $passkeyCount = $this->credentialRepository->countByBeUser($beUserUid);
        $graceValue = $userRow['passkey_grace_period_start'] ?? 0;
        $gracePeriodStart = \is_numeric($graceValue) ? (int) $graceValue : 0;
        $usergroupValue = $userRow['usergroup'] ?? '';
        $groupUids = GeneralUtility::intExplode(',', \is_string($usergroupValue) ? $usergroupValue : '', true);

        if ($groupUids === []) {
            return new EnforcementStatus(
                level: EnforcementLevel::Off,
                gracePeriodDays: 0,
                gracePeriodStart: $gracePeriodStart,
                hasPasskeys: $passkeyCount > 0,
            );
        }

        $groups = $this->fetchGroups(\array_values($groupUids));
        $effectiveLevel = EnforcementLevel::Off;
        $effectiveGraceDays = 0;

        foreach ($groups as $group) {
            $enforcementValue = $group['passkey_enforcement'] ?? '';
            $level = EnforcementLevel::tryFrom(\is_string($enforcementValue) ? $enforcementValue : '');
            $level ??= EnforcementLevel::Off;
            $graceDaysValue = $group['passkey_grace_period_days'] ?? 0;
            $graceDays = \is_numeric($graceDaysValue) ? (int) $graceDaysValue : 0;

            if ($level->severity() > $effectiveLevel->severity()) {
                $effectiveLevel = $level;
                $effectiveGraceDays = $graceDays;
            } elseif ($level->severity() === $effectiveLevel->severity() && $level->severity() > 0 && ($effectiveGraceDays === 0 || $graceDays < $effectiveGraceDays)) {
                $effectiveGraceDays = $graceDays;
            }
        }

        // Enforced level has no grace period — the interstitial is always mandatory
        if ($effectiveLevel === EnforcementLevel::Enforced) {
            $effectiveGraceDays = 0;
        }

        return new EnforcementStatus(
            level: $effectiveLevel,
            gracePeriodDays: $effectiveGraceDays,
            gracePeriodStart: $gracePeriodStart,
            hasPasskeys: $passkeyCount > 0,
        );
    }

    /**
     * Start the grace period for a backend user by recording the current timestamp.
     */
    public function startGracePeriod(int $beUserUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $connection->update('be_users', ['passkey_grace_period_start' => \time()], ['uid' => $beUserUid]);
        $this->logger->info('Grace period started', ['beUserUid' => $beUserUid]);
    }

    /**
     * Fetch backend user groups with their enforcement settings.
     *
     * @param list<int> $groupUids
     *
     * @return list<array<string, mixed>>
     */
    private function fetchGroups(array $groupUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');

        // Default restrictions (DeletedRestriction + HiddenRestriction + time restrictions)
        // are applied automatically by TYPO3's QueryBuilder, which is exactly what we need
        // for be_groups (respects deleted, hidden, starttime, endtime).
        return $queryBuilder
            ->select('uid', 'passkey_enforcement', 'passkey_grace_period_days')
            ->from('be_groups')
            ->where(
                $queryBuilder
                    ->expr()
                    ->in(
                        'uid',
                        $queryBuilder->createNamedParameter($groupUids, ArrayParameterType::INTEGER),
                    ),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
