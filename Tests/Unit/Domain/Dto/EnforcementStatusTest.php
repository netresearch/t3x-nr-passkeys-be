<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnforcementStatus::class)]
final class EnforcementStatusTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 1700000000,
            hasPasskeys: true,
        );
        self::assertSame(EnforcementLevel::Required, $status->level);
        self::assertSame(14, $status->gracePeriodDays);
        self::assertSame(1700000000, $status->gracePeriodStart);
        self::assertTrue($status->hasPasskeys);
    }

    // --- gracePeriodRemainingDays() ---
    #[Test]
    public function gracePeriodRemainingDaysReturnsFullDaysWhenNotStarted(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 30,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertSame(30, $status->gracePeriodRemainingDays());
    }

    #[Test]
    public function gracePeriodRemainingDaysCalculatesDaysLeft(): void
    {
        $now = 1700000000;
        $fiveDaysAgo = $now - 5 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );
        self::assertSame(9, $status->gracePeriodRemainingDays($now));
    }

    #[Test]
    public function gracePeriodRemainingDaysReturnsZeroWhenExpired(): void
    {
        $now = 1700000000;
        $twentyDaysAgo = $now - 20 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );
        self::assertSame(0, $status->gracePeriodRemainingDays($now));
    }

    #[Test]
    public function gracePeriodRemainingDaysReturnsZeroWhenExactlyExpired(): void
    {
        $now = 1700000000;
        $fourteenDaysAgo = $now - 14 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fourteenDaysAgo,
            hasPasskeys: false,
        );
        self::assertSame(0, $status->gracePeriodRemainingDays($now));
    }

    #[Test]
    public function gracePeriodRemainingDaysReturnsZeroForZeroGracePeriodDays(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertSame(0, $status->gracePeriodRemainingDays());
    }

    #[Test]
    public function gracePeriodRemainingDaysUsesFloorForPartialDays(): void
    {
        $now = 1700000000;

        // 5.5 days ago — floor(5.5)=5, ceil(5.5)=6, round(5.5)=6
        $fiveAndHalfDaysAgo = $now - (5 * 86400 + 43200);
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveAndHalfDaysAgo,
            hasPasskeys: false,
        );

        // floor gives 5 elapsed → 14-5=9; ceil/round would give 6 → 14-6=8
        self::assertSame(9, $status->gracePeriodRemainingDays($now));
    }

    #[Test]
    public function gracePeriodRemainingDaysUsesExactSecondsPerDay(): void
    {
        $now = 1700000000;

        // Exactly 86399 seconds ago (1 second less than a full day)
        $justUnderOneDay = $now - 86399;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 1,
            gracePeriodStart: $justUnderOneDay,
            hasPasskeys: false,
        );

        // floor(86399/86400)=0 → remaining=1; with 86399 divisor would give 1 → remaining=0
        self::assertSame(1, $status->gracePeriodRemainingDays($now));
    }

    // --- isGracePeriodExpired() ---
    #[Test]
    public function isGracePeriodExpiredReturnsFalseWhenNotStarted(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertFalse($status->isGracePeriodExpired());
    }

    #[Test]
    public function isGracePeriodExpiredReturnsTrueWhenFullyExpired(): void
    {
        $now = 1700000000;
        $twentyDaysAgo = $now - 20 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );
        self::assertTrue($status->isGracePeriodExpired($now));
    }

    #[Test]
    public function isGracePeriodExpiredReturnsTrueWhenExactlyExpired(): void
    {
        $now = 1700000000;
        $fourteenDaysAgo = $now - 14 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fourteenDaysAgo,
            hasPasskeys: false,
        );
        self::assertTrue($status->isGracePeriodExpired($now));
    }

    #[Test]
    public function isGracePeriodExpiredReturnsFalseWhenNotStartedEvenWithZeroGraceDays(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );

        // gracePeriodStart=0 guard must return false, even though
        // gracePeriodRemainingDays() would return 0 if the guard were bypassed
        self::assertFalse($status->isGracePeriodExpired());
    }

    #[Test]
    public function isGracePeriodExpiredReturnsFalseWhenStillActive(): void
    {
        $now = 1700000000;
        $fiveDaysAgo = $now - 5 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );
        self::assertFalse($status->isGracePeriodExpired($now));
    }

    // --- requiresInterstitial() ---
    #[Test]
    public function requiresInterstitialReturnsFalseWhenHasPasskeys(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: true,
        );
        self::assertFalse($status->requiresInterstitial());
    }

    #[Test]
    public function requiresInterstitialReturnsFalseForOffLevel(): void
    {
        $status = new EnforcementStatus(level: EnforcementLevel::Off, gracePeriodDays: 0, gracePeriodStart: 0, hasPasskeys: false);
        self::assertFalse($status->requiresInterstitial());
    }

    #[Test]
    public function requiresInterstitialReturnsFalseForEncourageLevel(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Encourage,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertFalse($status->requiresInterstitial());
    }

    #[Test]
    public function requiresInterstitialReturnsTrueForRequiredLevelWithoutPasskeys(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertTrue($status->requiresInterstitial());
    }

    #[Test]
    public function requiresInterstitialReturnsTrueForEnforcedLevelWithoutPasskeys(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertTrue($status->requiresInterstitial());
    }

    // --- canSkip() ---
    #[Test]
    public function canSkipReturnsFalseForEnforcedLevel(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 30,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertFalse($status->canSkip());
    }

    #[Test]
    public function canSkipReturnsFalseForRequiredWithExpiredGracePeriod(): void
    {
        $now = 1700000000;
        $twentyDaysAgo = $now - 20 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );
        self::assertFalse($status->canSkip($now));
    }

    #[Test]
    public function canSkipReturnsTrueForRequiredWithActiveGracePeriod(): void
    {
        $now = 1700000000;
        $fiveDaysAgo = $now - 5 * 86400;
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );
        self::assertTrue($status->canSkip($now));
    }

    #[Test]
    public function canSkipReturnsTrueForRequiredWithUnstartedGracePeriod(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertTrue($status->canSkip());
    }

    #[Test]
    public function canSkipReturnsTrueForEncourageLevel(): void
    {
        $status = new EnforcementStatus(
            level: EnforcementLevel::Encourage,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        self::assertTrue($status->canSkip());
    }

    #[Test]
    public function canSkipReturnsTrueForOffLevel(): void
    {
        $status = new EnforcementStatus(level: EnforcementLevel::Off, gracePeriodDays: 0, gracePeriodStart: 0, hasPasskeys: false);
        self::assertTrue($status->canSkip());
    }
}
