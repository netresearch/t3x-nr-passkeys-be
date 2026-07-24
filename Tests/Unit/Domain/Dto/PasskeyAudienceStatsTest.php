<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasskeyAudienceStats::class)]
final class PasskeyAudienceStatsTest extends TestCase
{
    #[Test]
    public function constructorExposesAllSegmentFields(): void
    {
        $stats = new PasskeyAudienceStats('backend', 10, 6, 12);

        self::assertSame('backend', $stats->audienceKey);
        self::assertSame(10, $stats->totalActiveUsers);
        self::assertSame(6, $stats->usersWithPasskeys);
        self::assertSame(12, $stats->activeCredentials);
    }

    #[Test]
    public function usersWithoutPasskeysIsTheDifference(): void
    {
        $stats = new PasskeyAudienceStats('frontend', 20, 5, 7);

        self::assertSame(15, $stats->usersWithoutPasskeys());
    }

    #[Test]
    public function usersWithoutPasskeysClampsToZeroOnInconsistentData(): void
    {
        // More passkey users than total users cannot happen with consistent
        // data, but the value object must never report a negative remainder.
        $stats = new PasskeyAudienceStats('backend', 2, 5, 3);

        self::assertSame(0, $stats->usersWithoutPasskeys());
    }
}
