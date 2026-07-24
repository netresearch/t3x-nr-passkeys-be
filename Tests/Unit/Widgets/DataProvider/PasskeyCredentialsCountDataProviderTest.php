<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyCredentialsCountDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasskeyCredentialsCountDataProvider::class)]
final class PasskeyCredentialsCountDataProviderTest extends TestCase
{
    private function provider(int $activeCredentials, string $audienceKey): PasskeyAdoptionStatsProviderInterface
    {
        $provider = $this->createMock(PasskeyAdoptionStatsProviderInterface::class);
        $provider->method('getAudienceStats')->willReturn(
            new PasskeyAudienceStats($audienceKey, 0, 0, $activeCredentials),
        );

        return $provider;
    }

    #[Test]
    public function getNumberSumsActiveCredentialsAcrossSegments(): void
    {
        $subject = new PasskeyCredentialsCountDataProvider([
            $this->provider(12, 'backend'),
            $this->provider(30, 'frontend'),
        ]);

        self::assertSame(42, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsSingleSegmentCountWhenOnlyBackendIsPresent(): void
    {
        $subject = new PasskeyCredentialsCountDataProvider([
            $this->provider(7, 'backend'),
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
