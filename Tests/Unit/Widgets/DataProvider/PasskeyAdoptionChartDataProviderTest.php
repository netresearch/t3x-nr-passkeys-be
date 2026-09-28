<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Tests\Unit\Widgets\Adoption\AdoptionStatsProviderMockTrait;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(PasskeyAdoptionChartDataProvider::class)]
final class PasskeyAdoptionChartDataProviderTest extends TestCase
{
    use AdoptionStatsProviderMockTrait;

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

    #[Test]
    public function singleBackendSegmentProducesOneDatasetWithPaletteAndSplit(): void
    {
        $chartData = $this
            ->subject([$this->statsProvider(new PasskeyAudienceStats('backend', 10, 6, 12))])
            ->getChartData();
        self::assertSame(['Backend: With passkeys', 'Backend: Without passkeys'], $chartData['labels']);
        self::assertCount(1, $chartData['datasets']);
        self::assertSame('Backend', $chartData['datasets'][0]['label']);
        self::assertSame(['#3f7f35', '#bd5d00'], $chartData['datasets'][0]['backgroundColor']);
        self::assertSame([6, 4], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function segmentsAreOrderedByAudienceKeyRegardlessOfRegistrationOrder(): void
    {
        // Registered frontend-first; output must be backend-then-frontend.
        $chartData = $this
            ->subject(
                [
                    $this->statsProvider(new PasskeyAudienceStats('frontend', 20, 5, 7)),
                    $this->statsProvider(new PasskeyAudienceStats('backend', 10, 6, 12)),
                ],
            )
            ->getChartData();
        self::assertCount(2, $chartData['datasets']);

        // One legend entry per audience and state, so the legend names every ring.
        self::assertSame(
            [
                'Backend: With passkeys',
                'Backend: Without passkeys',
                'Frontend: With passkeys',
                'Frontend: Without passkeys',
            ],
            $chartData['labels'],
        );

        // Every ring carries the full palette: the legend reads its swatches from dataset 0.
        $palette = ['#3f7f35', '#bd5d00', '#27808b', '#b8456b'];
        self::assertSame('Backend', $chartData['datasets'][0]['label']);
        self::assertSame($palette, $chartData['datasets'][0]['backgroundColor']);
        self::assertSame([6, 4, 0, 0], $chartData['datasets'][0]['data']);
        self::assertSame('Frontend', $chartData['datasets'][1]['label']);
        self::assertSame($palette, $chartData['datasets'][1]['backgroundColor']);
        self::assertSame([0, 0, 5, 15], $chartData['datasets'][1]['data']);
    }

    #[Test]
    public function usersWithoutPasskeysIsClampedToZero(): void
    {
        // More passkey users than total cannot happen with consistent data,
        // but the widget must never render a negative segment.
        $chartData = $this
            ->subject([$this->statsProvider(new PasskeyAudienceStats('backend', 2, 5, 3))])
            ->getChartData();
        self::assertSame([5, 0], $chartData['datasets'][0]['data']);
    }

    #[Test]
    public function unknownAudienceKeyFallsBackToDefaultColorsAndUcfirstLabel(): void
    {
        $chartData = $this
            ->subject([$this->statsProvider(new PasskeyAudienceStats('service', 4, 1, 2))])
            ->getChartData();
        self::assertCount(1, $chartData['datasets']);
        self::assertSame('Service', $chartData['datasets'][0]['label']);
        self::assertSame(['#3f7f35', '#bd5d00'], $chartData['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function emptyProviderCollectionYieldsNoDatasetsAndNoLabels(): void
    {
        $chartData = $this
            ->subject([])
            ->getChartData();
        self::assertSame([], $chartData['labels']);
        self::assertSame([], $chartData['datasets']);
    }

    #[Test]
    public function labelsAndSegmentUseTranslationsWhenLanguageServiceIsAvailable(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturnCallback(
                static fn(string $key): string => match ($key) {
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.label.with_passkeys' => 'Mit Passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.label.without_passkeys' => 'Ohne Passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.segment.backend' => 'Backend-Nutzer',
                    default => '',
                },
            );
        $GLOBALS['LANG'] = $languageService;
        $chartData = $this
            ->subject([$this->statsProvider(new PasskeyAudienceStats('backend', 1, 1, 1))])
            ->getChartData();
        self::assertSame(['Backend-Nutzer: Mit Passkeys', 'Backend-Nutzer: Ohne Passkeys'], $chartData['labels']);
        self::assertSame('Backend-Nutzer', $chartData['datasets'][0]['label']);
    }

    #[Test]
    public function everyColourKeepsThreeToOneAgainstTheWidgetSurfaceInBothSchemes(): void
    {
        // TYPO3 14.3 dashboard widget surface, measured on a live instance:
        // light #f4f4f6, dark #1d1c21 (the widgets are registered on 14.3+ only).
        $chartData = $this
            ->subject(
                [
                    $this->statsProvider(new PasskeyAudienceStats('backend', 2, 1, 1)),
                    $this->statsProvider(new PasskeyAudienceStats('frontend', 2, 1, 1)),
                ],
            )
            ->getChartData();

        foreach ($chartData['datasets'][0]['backgroundColor'] as $colour) {
            self::assertGreaterThanOrEqual(3.0, $this->contrast($colour, '#f4f4f6'), $colour . ' on light');
            self::assertGreaterThanOrEqual(3.0, $this->contrast($colour, '#1d1c21'), $colour . ' on dark');
        }
    }

    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channels = \array_map(
                static function (string $pair): float {
                    $v = \hexdec($pair) / 255;

                    return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
                },
                \str_split(\ltrim($hex, '#'), 2),
            );

            return (2126 * $channels[0] + 7152 * $channels[1] + 722 * $channels[2]) / 10000;
        };
        $la = $luminance($a);
        $lb = $luminance($b);

        return (\max($la, $lb) + 0.05) / (\min($la, $lb) + 0.05);
    }

    #[Test]
    public function everyLabelKeyTheProviderRequestsExistsInTheDashboardLanguageFile(): void
    {
        $prefix = 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:';
        $requested = [];
        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturnCallback(
                static function (string $key) use (&$requested): string {
                    $requested[] = $key;

                    return '';
                },
            );
        $GLOBALS['LANG'] = $languageService;
        $this
            ->subject(
                [
                    $this->statsProvider(new PasskeyAudienceStats('backend', 1, 1, 1)),
                    $this->statsProvider(new PasskeyAudienceStats('frontend', 1, 1, 1)),
                ],
            )
            ->getChartData();

        $xml = new \DOMDocument();
        self::assertTrue($xml->load(__DIR__ . '/../../../../Resources/Private/Language/locallang_dashboard.xlf'));
        $ids = [];

        foreach ($xml->getElementsByTagName('trans-unit') as $unit) {
            $ids[] = $prefix . $unit->getAttribute('id');
        }

        // A misspelt key would silently render the English fallback.
        self::assertContains($prefix . 'widget.adoption.label.with_passkeys', $requested);
        self::assertContains($prefix . 'widget.adoption.label.without_passkeys', $requested);
        self::assertContains($prefix . 'widget.adoption.segment.frontend', $requested);

        foreach (\array_unique($requested) as $key) {
            self::assertContains($key, $ids, $key);
        }
    }
}
