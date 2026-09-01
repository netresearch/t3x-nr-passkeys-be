<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

/**
 * One audience segment (backend users, frontend users, …) of the unified
 * passkey-adoption dashboard widgets. Immutable value object returned by
 * PasskeyAdoptionStatsProviderInterface implementations.
 */
final readonly class PasskeyAudienceStats
{
    /**
     * @param string $audienceKey       Stable machine key ('backend', 'frontend').
     *                                   Used for ordering and to resolve the
     *                                   segment label (widget.adoption.segment.<key>)
     *                                   and color pair. Must be [a-z0-9_]+.
     * @param int    $totalActiveUsers  Active (non-deleted, non-disabled) users in
     *                                   this audience.
     * @param int    $usersWithPasskeys Distinct users owning >=1 active credential.
     * @param int    $activeCredentials Active (non-revoked) credentials in this audience.
     */
    public function __construct(
        public string $audienceKey,
        public int $totalActiveUsers,
        public int $usersWithPasskeys,
        public int $activeCredentials,
    ) {}

    public function usersWithoutPasskeys(): int
    {
        return \max(0, $this->totalActiveUsers - $this->usersWithPasskeys);
    }
}
