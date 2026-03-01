<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupEnforcementInfo::class)]
final class GroupEnforcementInfoTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 5,
            title: 'Administrators',
            enforcement: 'enforced',
            gracePeriodDays: 0,
            totalUsers: 8,
            usersWithPasskeys: 6,
        );

        self::assertSame(5, $info->uid);
        self::assertSame('Administrators', $info->title);
        self::assertSame('enforced', $info->enforcement);
        self::assertSame(0, $info->gracePeriodDays);
        self::assertSame(8, $info->totalUsers);
        self::assertSame(6, $info->usersWithPasskeys);
    }

    #[Test]
    public function adoptionPercentageReturnsZeroForEmptyGroup(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 1,
            title: 'Empty Group',
            enforcement: 'required',
            gracePeriodDays: 14,
            totalUsers: 0,
            usersWithPasskeys: 0,
        );

        self::assertSame(0.0, $info->adoptionPercentage());
    }

    #[Test]
    public function adoptionPercentageReturnsCorrectPercentage(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 2,
            title: 'Editors',
            enforcement: 'required',
            gracePeriodDays: 14,
            totalUsers: 10,
            usersWithPasskeys: 7,
        );

        self::assertSame(70.0, $info->adoptionPercentage());
    }

    #[Test]
    public function adoptionPercentageRoundsToOneDecimal(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 3,
            title: 'Authors',
            enforcement: 'encourage',
            gracePeriodDays: 30,
            totalUsers: 3,
            usersWithPasskeys: 1,
        );

        self::assertSame(33.3, $info->adoptionPercentage());
    }

    #[Test]
    public function jsonSerializeReturnsExpectedArray(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 10,
            title: 'Editors',
            enforcement: 'required',
            gracePeriodDays: 14,
            totalUsers: 20,
            usersWithPasskeys: 15,
        );

        $expected = [
            'uid' => 10,
            'title' => 'Editors',
            'enforcement' => 'required',
            'gracePeriodDays' => 14,
            'totalUsers' => 20,
            'usersWithPasskeys' => 15,
            'adoptionPercentage' => 75.0,
        ];

        self::assertSame($expected, $info->jsonSerialize());
    }

    #[Test]
    public function jsonSerializeIncludesZeroAdoptionPercentageForEmptyGroup(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 11,
            title: 'New Group',
            enforcement: 'encourage',
            gracePeriodDays: 7,
            totalUsers: 0,
            usersWithPasskeys: 0,
        );

        $serialized = $info->jsonSerialize();

        self::assertSame(0.0, $serialized['adoptionPercentage']);
    }

    #[Test]
    public function jsonEncodeProducesValidJson(): void
    {
        $info = new GroupEnforcementInfo(
            uid: 1,
            title: 'Test Group',
            enforcement: 'required',
            gracePeriodDays: 14,
            totalUsers: 10,
            usersWithPasskeys: 5,
        );

        $json = \json_encode($info, \JSON_THROW_ON_ERROR);
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $decoded['uid']);
        self::assertSame('Test Group', $decoded['title']);
        self::assertEqualsWithDelta(50.0, $decoded['adoptionPercentage'], 0.01);
    }
}
