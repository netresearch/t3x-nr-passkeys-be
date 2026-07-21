<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Chart.js doughnut-chart data provider for backend-user passkey adoption.
 *
 * Splits the active (non-deleted, non-disabled) backend users into those
 * with at least one active passkey credential and those without. Reuses
 * the aggregate count queries of AdoptionStatsService instead of the full
 * getStats() computation — the widget only needs the two totals, not the
 * per-group breakdown or the passkey-less user list.
 */
final class PasskeyAdoptionChartDataProvider implements ChartDataProviderInterface
{
    use TranslationTrait;

    /**
     * Chart colors follow the TYPO3 dashboard default palette
     * (WidgetApi::getDefaultChartColors()), hardcoded because the
     * WidgetApi return type is untyped on TYPO3 v12.
     */
    private const COLOR_WITH_PASSKEYS = '#4c7e3a';
    private const COLOR_WITHOUT_PASSKEYS = '#ff8700';

    public function __construct(
        private readonly AdoptionStatsService $adoptionStatsService,
    ) {}

    /**
     * @return array{labels: list<string>, datasets: list<array{backgroundColor: list<string>, data: list<int>}>}
     */
    public function getChartData(): array
    {
        $totalUsers = $this->adoptionStatsService->countTotalActiveUsers();
        $usersWithPasskeys = $this->adoptionStatsService->countUsersWithPasskeys();
        $usersWithoutPasskeys = \max(0, $totalUsers - $usersWithPasskeys);

        return [
            'labels' => [
                $this->translate('widget.adoption.label.with_passkeys', 'With passkeys'),
                $this->translate('widget.adoption.label.without_passkeys', 'Without passkeys'),
            ],
            'datasets' => [
                [
                    'backgroundColor' => [
                        self::COLOR_WITH_PASSKEYS,
                        self::COLOR_WITHOUT_PASSKEYS,
                    ],
                    'data' => [$usersWithPasskeys, $usersWithoutPasskeys],
                ],
            ],
        ];
    }
}
