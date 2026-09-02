<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Fuzz;

use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnforcementLevel::class)]
#[CoversClass(EnforcementStatus::class)]
#[CoversClass(AdoptionStats::class)]
#[CoversClass(GroupEnforcementInfo::class)]
final class EnforcementFuzzTest extends TestCase
{
    /** @var EnforcementLevel[] */
    private array $allLevels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allLevels = EnforcementLevel::cases();
    }

    #[Test]
    public function tryFromWithRandomStringsNeverThrows(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $length = \random_int(0, 50);
            $input = $length > 0 ? \random_bytes(\random_int(1, 50)) : '';
            $result = EnforcementLevel::tryFrom($input);
            self::assertTrue(
                $result instanceof EnforcementLevel || $result === null,
                \sprintf('tryFrom() returned unexpected type for input of length %d', $length),
            );
        }
    }

    #[Test]
    public function tryFromWithKnownValuesAlwaysSucceeds(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $result = EnforcementLevel::tryFrom($level->value);
            self::assertSame($level, $result, \sprintf('tryFrom("%s") did not return expected level', $level->value));
        }
    }

    #[Test]
    public function gracePeriodRemainingDaysWithEdgeTimestamps(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $gracePeriodStart = \random_int(0, PHP_INT_MAX >> 2);
            $gracePeriodDays = \random_int(0, 3650);
            $currentTime = \random_int(0, PHP_INT_MAX >> 2);
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $status = new EnforcementStatus(
                level: $level,
                gracePeriodDays: $gracePeriodDays,
                gracePeriodStart: $gracePeriodStart,
                hasPasskeys: (bool) \random_int(0, 1),
            );
            $remaining = $status->gracePeriodRemainingDays($currentTime);
            self::assertGreaterThanOrEqual(0, $remaining, 'gracePeriodRemainingDays() returned a negative value');

            // When currentTime >= gracePeriodStart, remaining cannot exceed gracePeriodDays.
            // When currentTime < gracePeriodStart (future start), remaining can exceed
            // gracePeriodDays because elapsed is negative — this is expected behavior.
            if ($gracePeriodStart > 0 && $currentTime >= $gracePeriodStart) {
                self::assertLessThanOrEqual(
                    $gracePeriodDays,
                    $remaining,
                    'gracePeriodRemainingDays() exceeded gracePeriodDays when current >= start',
                );
            }
        }
    }

    #[Test]
    public function isGracePeriodExpiredConsistencyWithRemainingDays(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $gracePeriodStart = \random_int(1, PHP_INT_MAX >> 2);
            $gracePeriodDays = \random_int(0, 3650);
            $currentTime = \random_int(0, PHP_INT_MAX >> 2);
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $status = new EnforcementStatus(
                level: $level,
                gracePeriodDays: $gracePeriodDays,
                gracePeriodStart: $gracePeriodStart,
                hasPasskeys: (bool) \random_int(0, 1),
            );
            $expired = $status->isGracePeriodExpired($currentTime);
            $remaining = $status->gracePeriodRemainingDays($currentTime);
            self::assertSame(
                $remaining === 0,
                $expired,
                \sprintf(
                    'Inconsistency: remaining=%d but expired=%s (start=%d, days=%d, now=%d)',
                    $remaining,
                    $expired ? 'true' : 'false',
                    $gracePeriodStart,
                    $gracePeriodDays,
                    $currentTime,
                ),
            );
        }
    }

    #[Test]
    public function isGracePeriodExpiredReturnsFalseWhenNotStarted(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $gracePeriodDays = \random_int(0, 3650);
            $currentTime = \random_int(0, PHP_INT_MAX >> 2);
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $status = new EnforcementStatus(
                level: $level,
                gracePeriodDays: $gracePeriodDays,
                gracePeriodStart: 0,
                hasPasskeys: (bool) \random_int(0, 1),
            );
            self::assertFalse(
                $status->isGracePeriodExpired($currentTime),
                'isGracePeriodExpired() should be false when gracePeriodStart is 0',
            );
        }
    }

    #[Test]
    public function canSkipIsAlwaysBooleanAndConsistent(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $gracePeriodDays = \random_int(0, 3650);
            $gracePeriodStart = \random_int(0, PHP_INT_MAX >> 2);
            $currentTime = \random_int(0, PHP_INT_MAX >> 2);
            $hasPasskeys = (bool) \random_int(0, 1);
            $status = new EnforcementStatus(
                level: $level,
                gracePeriodDays: $gracePeriodDays,
                gracePeriodStart: $gracePeriodStart,
                hasPasskeys: $hasPasskeys,
            );
            $canSkip = $status->canSkip($currentTime);
            self::assertIsBool($canSkip);

            // Enforced level should never be skippable
            if ($level === EnforcementLevel::Enforced) {
                self::assertFalse($canSkip, 'canSkip() should be false for Enforced level');
            }

            // Required with expired grace period should not be skippable
            if ($level === EnforcementLevel::Required && $status->isGracePeriodExpired($currentTime)) {
                self::assertFalse($canSkip, 'canSkip() should be false for Required level with expired grace period');
            }

            // Off or Encourage should always be skippable
            if ($level === EnforcementLevel::Off || $level === EnforcementLevel::Encourage) {
                self::assertTrue($canSkip, \sprintf('canSkip() should be true for %s level', $level->value));
            }
        }
    }

    #[Test]
    public function severityOrderingIsConsistent(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $a = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $b = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $strictest = EnforcementLevel::strictest($a, $b);
            self::assertGreaterThanOrEqual(
                $a->severity(),
                $strictest->severity(),
                'strictest() returned a level with lower severity than input $a',
            );
            self::assertGreaterThanOrEqual(
                $b->severity(),
                $strictest->severity(),
                'strictest() returned a level with lower severity than input $b',
            );

            // strictest should be one of the two inputs
            self::assertTrue(
                $strictest === $a || $strictest === $b,
                'strictest() returned a level that is neither input',
            );

            // Commutative: strictest(a, b) should have same severity as strictest(b, a)
            $reversed = EnforcementLevel::strictest($b, $a);
            self::assertSame(
                $strictest->severity(),
                $reversed->severity(),
                'strictest() is not commutative in severity',
            );
        }
    }

    #[Test]
    public function adoptionStatsPercentageWithRandomValues(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $totalUsers = \random_int(0, 1000000);
            $usersWithPasskeys = \random_int(0, $totalUsers);
            $stats = new AdoptionStats(
                totalUsers: $totalUsers,
                usersWithPasskeys: $usersWithPasskeys,
                groups: [],
                usersWithoutPasskeys: [],
            );
            $percentage = $stats->adoptionPercentage();
            self::assertIsFloat($percentage);
            self::assertGreaterThanOrEqual(0.0, $percentage, 'adoptionPercentage() returned a negative value');
            self::assertLessThanOrEqual(100.0, $percentage, 'adoptionPercentage() exceeded 100.0');

            if ($totalUsers === 0) {
                self::assertSame(0.0, $percentage, 'adoptionPercentage() should be 0.0 when totalUsers is 0');
            }
        }
    }

    #[Test]
    public function groupEnforcementInfoPercentageWithRandomValues(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $totalUsers = \random_int(0, 1000000);
            $usersWithPasskeys = \random_int(0, $totalUsers);
            $level = $this->allLevels[\random_int(0, \count($this->allLevels) - 1)];
            $info = new GroupEnforcementInfo(
                uid: \random_int(1, PHP_INT_MAX >> 2),
                title: 'Group ' . \random_int(1, 999),
                enforcement: $level->value,
                gracePeriodDays: \random_int(0, 3650),
                totalUsers: $totalUsers,
                usersWithPasskeys: $usersWithPasskeys,
            );
            $percentage = $info->adoptionPercentage();
            self::assertIsFloat($percentage);
            self::assertGreaterThanOrEqual(
                0.0,
                $percentage,
                'GroupEnforcementInfo::adoptionPercentage() returned a negative value',
            );
            self::assertLessThanOrEqual(100.0, $percentage, 'GroupEnforcementInfo::adoptionPercentage() exceeded 100.0');

            if ($totalUsers === 0) {
                self::assertSame(
                    0.0,
                    $percentage,
                    'GroupEnforcementInfo::adoptionPercentage() should be 0.0 when totalUsers is 0',
                );
            }

            // Verify JSON serialization does not throw
            $json = $info->jsonSerialize();
            self::assertIsArray($json);
            self::assertArrayHasKey('adoptionPercentage', $json);
            self::assertSame($percentage, $json['adoptionPercentage']);
        }
    }
}
