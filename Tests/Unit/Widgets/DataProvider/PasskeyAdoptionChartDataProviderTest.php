<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(PasskeyAdoptionChartDataProvider::class)]
final class PasskeyAdoptionChartDataProviderTest extends TestCase
{
    private PasskeyAdoptionChartDataProvider $subject;

    private AdoptionStatsService&MockObject $adoptionStatsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $this->subject = new PasskeyAdoptionChartDataProvider($this->adoptionStatsService);

        // No LanguageService in unit context — the trait falls back to
        // the English default labels.
        unset($GLOBALS['LANG']);
    }

    #[Test]
    public function getChartDataSplitsUsersIntoWithAndWithoutPasskeys(): void
    {
        $this->adoptionStatsService->method('countTotalActiveUsers')->willReturn(10);
        $this->adoptionStatsService->method('countUsersWithPasskeys')->willReturn(6);

        $chartData = $this->subject->getChartData();

        self::assertSame(['With passkeys', 'Without passkeys'], $chartData['labels']);
        self::assertCount(1, $chartData['datasets']);
        self::assertSame([6, 4], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataProvidesOneColorPerSegment(): void
    {
        $this->adoptionStatsService->method('countTotalActiveUsers')->willReturn(3);
        $this->adoptionStatsService->method('countUsersWithPasskeys')->willReturn(1);

        $chartData = $this->subject->getChartData();

        $colors = $chartData['datasets'][0]['backgroundColor'];
        self::assertCount(2, $colors);
        self::assertCount(2, \array_unique($colors));

        foreach ($colors as $color) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $color);
        }
    }

    #[Test]
    public function getChartDataReturnsZerosForEmptyInstallation(): void
    {
        $this->adoptionStatsService->method('countTotalActiveUsers')->willReturn(0);
        $this->adoptionStatsService->method('countUsersWithPasskeys')->willReturn(0);

        $chartData = $this->subject->getChartData();

        self::assertSame([0, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function getChartDataUsesTranslatedLabelsWhenLanguageServiceIsAvailable(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => match ($key) {
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:widget.adoption.label.with_passkeys' => 'Mit Passkeys',
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:widget.adoption.label.without_passkeys' => 'Ohne Passkeys',
                default => '',
            },
        );
        $GLOBALS['LANG'] = $languageService;

        $this->adoptionStatsService->method('countTotalActiveUsers')->willReturn(1);
        $this->adoptionStatsService->method('countUsersWithPasskeys')->willReturn(1);

        $chartData = $this->subject->getChartData();

        self::assertSame(['Mit Passkeys', 'Ohne Passkeys'], $chartData['labels']);
    }

    #[Test]
    public function getChartDataClampsNegativeRemainderToZero(): void
    {
        // More passkey users than total users cannot happen with consistent
        // data, but the widget must never render a negative segment.
        $this->adoptionStatsService->method('countTotalActiveUsers')->willReturn(2);
        $this->adoptionStatsService->method('countUsersWithPasskeys')->willReturn(5);

        $chartData = $this->subject->getChartData();

        self::assertSame([5, 0], $chartData['datasets'][0]['data']);
    }
}
