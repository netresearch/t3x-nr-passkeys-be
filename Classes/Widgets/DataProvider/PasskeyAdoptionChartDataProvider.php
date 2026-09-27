<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Doughnut data for unified passkey adoption. One dataset (ring) per audience
 * segment contributed via the nr_passkeys_be.adoption_stats_provider tag:
 * backend (be_users) always, frontend (fe_users) when nr_passkeys_fe is present.
 * Populations differ, so segments are NEVER summed into one ratio.
 */
final readonly class PasskeyAdoptionChartDataProvider implements ChartDataProviderInterface
{
    /**
     * The widget's labels live beside its title and description; the shared
     * TranslationTrait reads locallang.xlf, where the segment keys do not exist.
     */
    private const LABELS = 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:';

    /**
     * audienceKey => [withPasskeysColor, withoutPasskeysColor].
     *
     * Chart.js paints on a canvas, so CSS variables cannot reach these. Every
     * colour keeps at least 3:1 against the dashboard widget surface in both
     * backend schemes (TYPO3 14.3: #f4f4f6 light, #1d1c21 dark), which bounds
     * relative luminance to roughly 0.14-0.26. Two colours inside that band
     * cannot reach 3:1 against each other, so adjacent segments are told apart
     * by the border core draws between them (white in light, black in dark
     * scheme) and by hue, never by lightness alone.
     *
     * @var array<string, array{string, string}>
     */
    private const AUDIENCE_COLORS = [
        'backend' => ['#3f7f35', '#bd5d00'],
        // green / orange
        'frontend' => ['#27808b', '#b8456b'],
    ];

    /**
     * @var array{string, string}
     */
    private const FALLBACK_COLORS = ['#3f7f35', '#bd5d00'];

    /**
     * @param iterable<PasskeyAdoptionStatsProviderInterface> $statsProviders
     */
    public function __construct(private iterable $statsProviders) {}

    /**
     * One legend entry per audience and state ("Backend users: With passkeys"):
     * the doughnut legend takes its entries from `labels` and its swatches from
     * the first dataset, so shared labels would show only the first ring's
     * colours. Each ring therefore carries the full label list, with zeros
     * outside its own pair, and every ring carries the full colour list.
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, backgroundColor: list<string>, data: list<int>}>}
     */
    public function getChartData(): array
    {
        $segments = [];

        foreach ($this->statsProviders as $provider) {
            $segments[] = $provider->getAudienceStats();
        }

        // Deterministic order regardless of DI registration order.
        \usort(
            $segments,
            static fn(
                PasskeyAudienceStats $a,
                PasskeyAudienceStats $b,
            ): int => \strcmp($a->audienceKey, $b->audienceKey),
        );
        $withLabel = $this->label('widget.adoption.label.with_passkeys', 'With passkeys');
        $withoutLabel = $this->label('widget.adoption.label.without_passkeys', 'Without passkeys');
        $labels = [];
        $colors = [];
        $segmentLabels = [];

        foreach ($segments as $segment) {
            $segmentLabel = $this->label('widget.adoption.segment.' . $segment->audienceKey, \ucfirst($segment->audienceKey));
            $segmentLabels[] = $segmentLabel;
            $pair = self::AUDIENCE_COLORS[$segment->audienceKey] ?? self::FALLBACK_COLORS;
            $labels[] = $segmentLabel . ': ' . $withLabel;
            $labels[] = $segmentLabel . ': ' . $withoutLabel;
            $colors[] = $pair[0];
            $colors[] = $pair[1];
        }

        $datasets = [];
        $slots = \count($labels);

        foreach ($segments as $index => $segment) {
            $data = \array_merge(
                \array_fill(0, 2 * $index, 0),
                [$segment->usersWithPasskeys, $segment->usersWithoutPasskeys()],
                \array_fill(0, $slots - 2 * $index - 2, 0),
            );
            $datasets[] = ['label' => $segmentLabels[$index], 'backgroundColor' => $colors, 'data' => $data];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    private function label(string $key, string $fallback): string
    {
        $lang = $GLOBALS['LANG'] ?? null;

        if ($lang instanceof LanguageService) {
            $translated = $lang->sL(self::LABELS . $key);

            if ($translated !== '') {
                return $translated;
            }
        }

        return $fallback;
    }
}
