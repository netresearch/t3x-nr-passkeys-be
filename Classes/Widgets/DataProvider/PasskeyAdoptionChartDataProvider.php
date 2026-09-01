<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Doughnut data for unified passkey adoption. One dataset (ring) per audience
 * segment contributed via the nr_passkeys_be.adoption_stats_provider tag:
 * backend (be_users) always, frontend (fe_users) when nr_passkeys_fe is present.
 * Populations differ, so segments are NEVER summed into one ratio.
 */
final readonly class PasskeyAdoptionChartDataProvider implements ChartDataProviderInterface
{
    use TranslationTrait;

    /**
     * audienceKey => [withPasskeysColor, withoutPasskeysColor].
     *
     * @var array<string, array{string, string}>
     */
    private const AUDIENCE_COLORS = [
        'backend' => ['#4c7e3a', '#ff8700'],
        // green / orange (current BE palette)
        'frontend' => ['#2f99a4', '#c83c5a'],
    ];

    /**
     * @var array{string, string}
     */
    private const FALLBACK_COLORS = ['#4c7e3a', '#ff8700'];

    /**
     * @param iterable<PasskeyAdoptionStatsProviderInterface> $statsProviders
     */
    public function __construct(private iterable $statsProviders) {}

    /**
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
            static fn(PasskeyAudienceStats $a, PasskeyAudienceStats $b): int => \strcmp($a->audienceKey, $b->audienceKey),
        );
        $datasets = [];

        foreach ($segments as $segment) {
            $colors = self::AUDIENCE_COLORS[$segment->audienceKey] ?? self::FALLBACK_COLORS;
            $datasets[] = [
                'label' => $this->translate('widget.adoption.segment.' . $segment->audienceKey, \ucfirst($segment->audienceKey)),
                'backgroundColor' => [$colors[0], $colors[1]],
                'data' => [$segment->usersWithPasskeys, $segment->usersWithoutPasskeys()],
            ];
        }

        return [
            'labels' => [
                $this->translate('widget.adoption.label.with_passkeys', 'With passkeys'),
                $this->translate('widget.adoption.label.without_passkeys', 'Without passkeys'),
            ],
            'datasets' => $datasets,
        ];
    }
}
