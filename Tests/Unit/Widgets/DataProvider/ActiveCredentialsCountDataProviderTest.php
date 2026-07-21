<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\ActiveCredentialsCountDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveCredentialsCountDataProvider::class)]
final class ActiveCredentialsCountDataProviderTest extends TestCase
{
    #[Test]
    public function getNumberReturnsActiveCredentialCount(): void
    {
        $adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $adoptionStatsService
            ->expects(self::once())
            ->method('countActiveCredentials')
            ->willReturn(42);

        $subject = new ActiveCredentialsCountDataProvider($adoptionStatsService);

        self::assertSame(42, $subject->getNumber());
    }

    #[Test]
    public function getNumberReturnsZeroForEmptyInstallation(): void
    {
        $adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $adoptionStatsService->method('countActiveCredentials')->willReturn(0);

        $subject = new ActiveCredentialsCountDataProvider($adoptionStatsService);

        self::assertSame(0, $subject->getNumber());
    }
}
