<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Tests\Unit\Widgets\Adoption\AdoptionStatsProviderMockTrait;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyCredentialsCountDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasskeyCredentialsCountDataProvider::class)]
final class PasskeyCredentialsCountDataProviderTest extends TestCase
{
    use AdoptionStatsProviderMockTrait;

    #[Test]
    public function getNumberSumsActiveCredentialsAcrossSegments(): void
    {
        $subject = new PasskeyCredentialsCountDataProvider([
            $this->statsProvider(new PasskeyAudienceStats('backend', 0, 0, 12)),
            $this->statsProvider(new PasskeyAudienceStats('frontend', 0, 0, 30)),
        ]);

        self::assertSame(42, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsSingleSegmentCountWhenOnlyBackendIsPresent(): void
    {
        $subject = new PasskeyCredentialsCountDataProvider([
            $this->statsProvider(new PasskeyAudienceStats('backend', 0, 0, 7)),
        ]);

        self::assertSame(7, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsZeroForEmptyCollection(): void
    {
        $subject = new PasskeyCredentialsCountDataProvider([]);

        self::assertSame(0, $subject->getNumber());
    }
}
