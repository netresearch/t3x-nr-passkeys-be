<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Enum;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnforcementLevel::class)]
final class EnforcementLevelTest extends TestCase
{
    #[Test]
    public function offHasCorrectBackingValue(): void
    {
        self::assertSame('off', EnforcementLevel::Off->value);
    }

    #[Test]
    public function encourageHasCorrectBackingValue(): void
    {
        self::assertSame('encourage', EnforcementLevel::Encourage->value);
    }

    #[Test]
    public function requiredHasCorrectBackingValue(): void
    {
        self::assertSame('required', EnforcementLevel::Required->value);
    }

    #[Test]
    public function enforcedHasCorrectBackingValue(): void
    {
        self::assertSame('enforced', EnforcementLevel::Enforced->value);
    }

    #[Test]
    public function offSeverityIsZero(): void
    {
        self::assertSame(0, EnforcementLevel::Off->severity());
    }

    #[Test]
    public function encourageSeverityIsOne(): void
    {
        self::assertSame(1, EnforcementLevel::Encourage->severity());
    }

    #[Test]
    public function requiredSeverityIsTwo(): void
    {
        self::assertSame(2, EnforcementLevel::Required->severity());
    }

    #[Test]
    public function enforcedSeverityIsThree(): void
    {
        self::assertSame(3, EnforcementLevel::Enforced->severity());
    }

    /**
     * @return iterable<string, array{EnforcementLevel, EnforcementLevel, EnforcementLevel}>
     */
    public static function strictestProvider(): iterable
    {
        yield 'off vs off' => [EnforcementLevel::Off, EnforcementLevel::Off, EnforcementLevel::Off];

        yield 'off vs encourage' => [EnforcementLevel::Off, EnforcementLevel::Encourage, EnforcementLevel::Encourage];

        yield 'encourage vs off' => [EnforcementLevel::Encourage, EnforcementLevel::Off, EnforcementLevel::Encourage];

        yield 'encourage vs required' => [EnforcementLevel::Encourage, EnforcementLevel::Required, EnforcementLevel::Required];

        yield 'required vs encourage' => [EnforcementLevel::Required, EnforcementLevel::Encourage, EnforcementLevel::Required];

        yield 'required vs enforced' => [EnforcementLevel::Required, EnforcementLevel::Enforced, EnforcementLevel::Enforced];

        yield 'enforced vs required' => [EnforcementLevel::Enforced, EnforcementLevel::Required, EnforcementLevel::Enforced];

        yield 'enforced vs enforced' => [EnforcementLevel::Enforced, EnforcementLevel::Enforced, EnforcementLevel::Enforced];

        yield 'off vs enforced' => [EnforcementLevel::Off, EnforcementLevel::Enforced, EnforcementLevel::Enforced];

        yield 'enforced vs off' => [EnforcementLevel::Enforced, EnforcementLevel::Off, EnforcementLevel::Enforced];
    }

    #[Test]
    #[DataProvider('strictestProvider')]
    public function strictestReturnsHigherSeverity(
        EnforcementLevel $a,
        EnforcementLevel $b,
        EnforcementLevel $expected,
    ): void {
        self::assertSame($expected, EnforcementLevel::strictest($a, $b));
    }

    #[Test]
    public function offDoesNotRequireInterstitial(): void
    {
        self::assertFalse(EnforcementLevel::Off->requiresInterstitial());
    }

    #[Test]
    public function encourageDoesNotRequireInterstitial(): void
    {
        self::assertFalse(EnforcementLevel::Encourage->requiresInterstitial());
    }

    #[Test]
    public function requiredRequiresInterstitial(): void
    {
        self::assertTrue(EnforcementLevel::Required->requiresInterstitial());
    }

    #[Test]
    public function enforcedRequiresInterstitial(): void
    {
        self::assertTrue(EnforcementLevel::Enforced->requiresInterstitial());
    }

    #[Test]
    public function offDoesNotRequireBanner(): void
    {
        self::assertFalse(EnforcementLevel::Off->requiresBanner());
    }

    #[Test]
    public function encourageRequiresBanner(): void
    {
        self::assertTrue(EnforcementLevel::Encourage->requiresBanner());
    }

    #[Test]
    public function requiredRequiresBanner(): void
    {
        self::assertTrue(EnforcementLevel::Required->requiresBanner());
    }

    #[Test]
    public function enforcedRequiresBanner(): void
    {
        self::assertTrue(EnforcementLevel::Enforced->requiresBanner());
    }

    #[Test]
    public function canBeCreatedFromBackingValue(): void
    {
        self::assertSame(EnforcementLevel::Required, EnforcementLevel::from('required'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(EnforcementLevel::tryFrom('invalid'));
    }

    #[Test]
    public function allCasesAreAvailable(): void
    {
        self::assertCount(4, EnforcementLevel::cases());
    }
}
