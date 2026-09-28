<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets;

use Netresearch\NrPasskeysBe\Widgets\PasskeyAdoptionChartWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;

#[CoversClass(PasskeyAdoptionChartWidget::class)]
final class PasskeyAdoptionChartWidgetTest extends TestCase
{
    #[Test]
    public function legendUsesSmallColourBoxesAndKeepsCoresConfiguration(): void
    {
        $data = ['labels' => ['Frontend: without passkeys'], 'datasets' => []];
        $provider = $this->createMock(ChartDataProviderInterface::class);
        $provider
            ->method('getChartData')
            ->willReturn($data);
        $widget = new PasskeyAdoptionChartWidget(
            configuration: $this->createMock(WidgetConfigurationInterface::class),
            dataProvider: $provider,
            backendViewFactory: $this->createMock(BackendViewFactory::class),
        );
        $graphConfig = $widget->getEventData()['graphConfig'];
        self::assertIsArray($graphConfig);

        // 12 px instead of Chart.js's 40 px, so the longest entry fits a 178 px canvas.
        self::assertSame(
            ['boxWidth' => 12, 'boxHeight' => 12],
            $graphConfig['options']['plugins']['legend']['labels'] ?? null,
        );

        // Core's own settings survive the override.
        self::assertSame('doughnut', $graphConfig['type']);
        self::assertTrue($graphConfig['options']['plugins']['legend']['display'] ?? null);
        self::assertSame('bottom', $graphConfig['options']['plugins']['legend']['position'] ?? null);
        self::assertSame($data, $graphConfig['data']);
    }
}
