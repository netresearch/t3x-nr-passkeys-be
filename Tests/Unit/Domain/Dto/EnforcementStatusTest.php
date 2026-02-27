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
            gracePeriodStart: 1_700_000_000,
            hasPasskeys: true,
        );

        self::assertSame(EnforcementLevel::Required, $status->level);
        self::assertSame(14, $status->gracePeriodDays);
        self::assertSame(1_700_000_000, $status->gracePeriodStart);
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
        $now = time();
        $fiveDaysAgo = $now - (5 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );

        self::assertSame(9, $status->gracePeriodRemainingDays());
    }

    #[Test]
    public function gracePeriodRemainingDaysReturnsZeroWhenExpired(): void
    {
        $now = time();
        $twentyDaysAgo = $now - (20 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );

        self::assertSame(0, $status->gracePeriodRemainingDays());
    }

    #[Test]
    public function gracePeriodRemainingDaysReturnsZeroWhenExactlyExpired(): void
    {
        $now = time();
        $fourteenDaysAgo = $now - (14 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fourteenDaysAgo,
            hasPasskeys: false,
        );

        self::assertSame(0, $status->gracePeriodRemainingDays());
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
        $now = time();
        $twentyDaysAgo = $now - (20 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );

        self::assertTrue($status->isGracePeriodExpired());
    }

    #[Test]
    public function isGracePeriodExpiredReturnsTrueWhenExactlyExpired(): void
    {
        $now = time();
        $fourteenDaysAgo = $now - (14 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fourteenDaysAgo,
            hasPasskeys: false,
        );

        self::assertTrue($status->isGracePeriodExpired());
    }

    #[Test]
    public function isGracePeriodExpiredReturnsFalseWhenStillActive(): void
    {
        $now = time();
        $fiveDaysAgo = $now - (5 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );

        self::assertFalse($status->isGracePeriodExpired());
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
        $status = new EnforcementStatus(
            level: EnforcementLevel::Off,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );

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
        $now = time();
        $twentyDaysAgo = $now - (20 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $twentyDaysAgo,
            hasPasskeys: false,
        );

        self::assertFalse($status->canSkip());
    }

    #[Test]
    public function canSkipReturnsTrueForRequiredWithActiveGracePeriod(): void
    {
        $now = time();
        $fiveDaysAgo = $now - (5 * 86_400);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $fiveDaysAgo,
            hasPasskeys: false,
        );

        self::assertTrue($status->canSkip());
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
        $status = new EnforcementStatus(
            level: EnforcementLevel::Off,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );

        self::assertTrue($status->canSkip());
    }
}
