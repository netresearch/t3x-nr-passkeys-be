<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use JsonSerializable;

/**
 * Read-only projection of a backend user group's passkey enforcement status.
 *
 * Used by the admin dashboard to display per-group adoption rates.
 */
final readonly class GroupEnforcementInfo implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $title,
        public string $enforcement,
        public int $gracePeriodDays,
        public int $totalUsers,
        public int $usersWithPasskeys,
    ) {}

    /**
     * Percentage of group members who have registered at least one passkey.
     *
     * Returns 0.0 when the group has no members.
     */
    public function adoptionPercentage(): float
    {
        if ($this->totalUsers === 0) {
            return 0.0;
        }

        return round(($this->usersWithPasskeys / $this->totalUsers) * 100, 1);
    }

    /**
     * @return array{uid: int, title: string, enforcement: string, gracePeriodDays: int, totalUsers: int, usersWithPasskeys: int, adoptionPercentage: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'title' => $this->title,
            'enforcement' => $this->enforcement,
            'gracePeriodDays' => $this->gracePeriodDays,
            'totalUsers' => $this->totalUsers,
            'usersWithPasskeys' => $this->usersWithPasskeys,
            'adoptionPercentage' => $this->adoptionPercentage(),
        ];
    }
}
