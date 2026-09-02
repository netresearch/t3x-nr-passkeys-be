<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\Adoption;

use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Service\BackendPasskeyAdoptionStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendPasskeyAdoptionStatsProvider::class)]
final class BackendPasskeyAdoptionStatsProviderTest extends TestCase
{
    #[Test]
    public function getAudienceStatsMapsTheThreeAggregatesToTheBackendSegment(): void
    {
        $adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $adoptionStatsService
            ->method('countTotalActiveUsers')
            ->willReturn(10);
        $adoptionStatsService
            ->method('countUsersWithPasskeys')
            ->willReturn(6);
        $adoptionStatsService
            ->method('countActiveCredentials')
            ->willReturn(12);
        $stats = (new BackendPasskeyAdoptionStatsProvider($adoptionStatsService))->getAudienceStats();
        self::assertSame('backend', $stats->audienceKey);
        self::assertSame(10, $stats->totalActiveUsers);
        self::assertSame(6, $stats->usersWithPasskeys);
        self::assertSame(12, $stats->activeCredentials);
    }
}
