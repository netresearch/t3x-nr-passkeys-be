<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;

/**
 * Backend-user (be_users / tx_nrpasskeysbe_credential) segment provider.
 * Thin adapter over the existing AdoptionStatsService aggregate queries.
 */
final readonly class BackendPasskeyAdoptionStatsProvider implements PasskeyAdoptionStatsProviderInterface
{
    public function __construct(private AdoptionStatsService $adoptionStatsService) {}

    public function getAudienceStats(): PasskeyAudienceStats
    {
        return new PasskeyAudienceStats(
            audienceKey: 'backend',
            totalActiveUsers: $this->adoptionStatsService->countTotalActiveUsers(),
            usersWithPasskeys: $this->adoptionStatsService->countUsersWithPasskeys(),
            activeCredentials: $this->adoptionStatsService->countActiveCredentials(),
        );
    }
}
