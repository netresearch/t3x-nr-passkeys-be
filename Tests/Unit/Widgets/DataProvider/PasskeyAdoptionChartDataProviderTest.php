<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(PasskeyAdoptionChartDataProvider::class)]
final class PasskeyAdoptionChartDataProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No LanguageService in unit context — the trait falls back to
        // the English default labels / ucfirst() segment labels.
        unset($GLOBALS['LANG']);
    }

    /**
     * @param iterable<PasskeyAdoptionStatsProviderInterface> $providers
     */
    private function subject(iterable $providers): PasskeyAdoptionChartDataProvider
    {
        return new PasskeyAdoptionChartDataProvider($providers);
    }

    private function provider(PasskeyAudienceStats $stats): PasskeyAdoptionStatsProviderInterface
    {
        $provider = $this->createMock(PasskeyAdoptionStatsProviderInterface::class);
        $provider->method('getAudienceStats')->willReturn($stats);

        return $provider;
    }

    #[Test]
    public function singleBackendSegmentProducesOneDatasetWithPaletteAndSplit(): void
    {
        $chartData = $this->subject([
            $this->provider(new PasskeyAudienceStats('backend', 10, 6, 12)),
        ])->getChartData();

        self::assertSame(['With passkeys', 'Without passkeys'], $chartData['labels']);
        self::assertCount(1, $chartData['datasets']);
        self::assertSame('Backend', $chartData['datasets'][0]['label']);
        self::assertSame(['#4c7e3a', '#ff8700'], $chartData['datasets'][0]['backgroundColor']);
        self::assertSame([6, 4], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function segmentsAreOrderedByAudienceKeyRegardlessOfRegistrationOrder(): void
    {
        // Registered frontend-first; output must be backend-then-frontend.
        $chartData = $this->subject([
            $this->provider(new PasskeyAudienceStats('frontend', 20, 5, 7)),
            $this->provider(new PasskeyAudienceStats('backend', 10, 6, 12)),
        ])->getChartData();

        self::assertCount(2, $chartData['datasets']);

        self::assertSame('Backend', $chartData['datasets'][0]['label']);
        self::assertSame(['#4c7e3a', '#ff8700'], $chartData['datasets'][0]['backgroundColor']);
        self::assertSame([6, 4], $chartData['datasets'][0]['data']);

        self::assertSame('Frontend', $chartData['datasets'][1]['label']);
        self::assertSame(['#2f99a4', '#c83c5a'], $chartData['datasets'][1]['backgroundColor']);
        self::assertSame([5, 15], $chartData['datasets'][1]['data']);
    }

    #[Test]
    public function usersWithoutPasskeysIsClampedToZero(): void
    {
        // More passkey users than total cannot happen with consistent data,
        // but the widget must never render a negative segment.
        $chartData = $this->subject([
            $this->provider(new PasskeyAudienceStats('backend', 2, 5, 3)),
        ])->getChartData();

        self::assertSame([5, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function unknownAudienceKeyFallsBackToDefaultColorsAndUcfirstLabel(): void
    {
        $chartData = $this->subject([
            $this->provider(new PasskeyAudienceStats('service', 4, 1, 2)),
        ])->getChartData();

        self::assertCount(1, $chartData['datasets']);
        self::assertSame('Service', $chartData['datasets'][0]['label']);
        self::assertSame(['#4c7e3a', '#ff8700'], $chartData['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function emptyProviderCollectionYieldsNoDatasetsButKeepsLabels(): void
    {
        $chartData = $this->subject([])->getChartData();

        self::assertSame(['With passkeys', 'Without passkeys'], $chartData['labels']);
        self::assertSame([], $chartData['datasets']);
    }

    #[Test]
    public function labelsAndSegmentUseTranslationsWhenLanguageServiceIsAvailable(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => match ($key) {
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:widget.adoption.label.with_passkeys' => 'Mit Passkeys',
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:widget.adoption.label.without_passkeys' => 'Ohne Passkeys',
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:widget.adoption.segment.backend' => 'Backend-Nutzer',
                default => '',
            },
        );
        $GLOBALS['LANG'] = $languageService;

        $chartData = $this->subject([
            $this->provider(new PasskeyAudienceStats('backend', 1, 1, 1)),
        ])->getChartData();

        self::assertSame(['Mit Passkeys', 'Ohne Passkeys'], $chartData['labels']);
        self::assertSame('Backend-Nutzer', $chartData['datasets'][0]['label']);
    }
}
