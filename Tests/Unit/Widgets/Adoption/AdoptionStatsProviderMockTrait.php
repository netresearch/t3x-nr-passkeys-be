<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\Adoption;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Shared test arrangement for the unified dashboard data providers: builds a
 * fake audience-stats provider from a PasskeyAudienceStats. Kept in one place
 * so the two data-provider tests do not each carry an identical mock helper.
 */
trait AdoptionStatsProviderMockTrait
{
    /**
     * @return PasskeyAdoptionStatsProviderInterface&MockObject
     */
    private function statsProvider(PasskeyAudienceStats $stats): PasskeyAdoptionStatsProviderInterface
    {
        $provider = $this->createMock(PasskeyAdoptionStatsProviderInterface::class);
        $provider
            ->method('getAudienceStats')
            ->willReturn($stats);

        return $provider;
    }
}
