<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

/**
 * Read-only snapshot of passkey adoption statistics for the admin dashboard.
 *
 * Aggregates user counts, per-group enforcement details, and a list of
 * users who have not yet registered any passkeys.
 */
final readonly class AdoptionStats
{
    /**
     * @param list<GroupEnforcementInfo> $groups
     * @param list<UserPasskeyStatus>    $usersWithoutPasskeys
     */
    public function __construct(
        public int $totalUsers,
        public int $usersWithPasskeys,
        public array $groups,
        public array $usersWithoutPasskeys,
        public bool $usersWithoutPasskeysTruncated = false,
    ) {}

    /**
     * Percentage of active users who have registered at least one passkey.
     *
     * Returns 0.0 when there are no active users.
     */
    public function adoptionPercentage(): float
    {
        if ($this->totalUsers === 0) {
            return 0.0;
        }

        return \round($this->usersWithPasskeys / $this->totalUsers * 100, 1);
    }
}
