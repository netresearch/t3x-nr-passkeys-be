<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdoptionStats::class)]
final class AdoptionStatsTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $group = new GroupEnforcementInfo(
            uid: 1,
            title: 'Editors',
            enforcement: 'required',
            gracePeriodDays: 14,
            totalUsers: 10,
            usersWithPasskeys: 5,
        );

        $user = new UserPasskeyStatus(
            uid: 42,
            username: 'jdoe',
            realName: 'John Doe',
            groups: '1,2',
            gracePeriodStart: 1_700_000_000,
            gracePeriodRemainingDays: 7,
        );

        $stats = new AdoptionStats(
            totalUsers: 100,
            usersWithPasskeys: 75,
            groups: [$group],
            usersWithoutPasskeys: [$user],
        );

        self::assertSame(100, $stats->totalUsers);
        self::assertSame(75, $stats->usersWithPasskeys);
        self::assertCount(1, $stats->groups);
        self::assertSame($group, $stats->groups[0]);
        self::assertCount(1, $stats->usersWithoutPasskeys);
        self::assertSame($user, $stats->usersWithoutPasskeys[0]);
    }

    #[Test]
    public function adoptionPercentageReturnsZeroForZeroUsers(): void
    {
        $stats = new AdoptionStats(
            totalUsers: 0,
            usersWithPasskeys: 0,
            groups: [],
            usersWithoutPasskeys: [],
        );

        self::assertSame(0.0, $stats->adoptionPercentage());
    }

    #[Test]
    public function adoptionPercentageReturnsCorrectPercentage(): void
    {
        $stats = new AdoptionStats(
            totalUsers: 200,
            usersWithPasskeys: 150,
            groups: [],
            usersWithoutPasskeys: [],
        );

        self::assertSame(75.0, $stats->adoptionPercentage());
    }

    #[Test]
    public function adoptionPercentageRoundsToOneDecimal(): void
    {
        $stats = new AdoptionStats(
            totalUsers: 3,
            usersWithPasskeys: 1,
            groups: [],
            usersWithoutPasskeys: [],
        );

        self::assertSame(33.3, $stats->adoptionPercentage());
    }

    #[Test]
    public function adoptionPercentageReturnsHundredWhenAllUsersHavePasskeys(): void
    {
        $stats = new AdoptionStats(
            totalUsers: 50,
            usersWithPasskeys: 50,
            groups: [],
            usersWithoutPasskeys: [],
        );

        self::assertSame(100.0, $stats->adoptionPercentage());
    }
}
