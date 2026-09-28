<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets;

use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;

/**
 * Core doughnut chart with a legend that fits the small widget.
 *
 * The legend names every segment ("Frontend: without passkeys"). A small
 * widget's canvas is 178-181 px wide just above the dashboard's four-column
 * breakpoint (about 1240 px viewport, TYPO3 13.4 and 14.3), and with
 * Chart.js's default 40 px colour box the longest entry does not fit, so
 * Chart.js cuts it off. A 12 px box leaves the text the room it needs.
 * Everything else is core's configuration.
 */
class PasskeyAdoptionChartWidget extends DoughnutChartWidget
{
    /**
     * Width and height of a legend colour box, in CSS pixels.
     */
    public const LEGEND_BOX_SIZE = 12;

    /**
     * @return array<array-key, mixed>
     */
    public function getEventData(): array
    {
        $eventData = parent::getEventData();
        $graphConfig = \is_array($eventData['graphConfig'] ?? null) ? $eventData['graphConfig'] : [];
        $options = \is_array($graphConfig['options'] ?? null) ? $graphConfig['options'] : [];
        $plugins = \is_array($options['plugins'] ?? null) ? $options['plugins'] : [];
        $legend = \is_array($plugins['legend'] ?? null) ? $plugins['legend'] : [];
        $legend['labels'] = ['boxWidth' => self::LEGEND_BOX_SIZE, 'boxHeight' => self::LEGEND_BOX_SIZE];
        $plugins['legend'] = $legend;
        $options['plugins'] = $plugins;
        $graphConfig['options'] = $options;
        $eventData['graphConfig'] = $graphConfig;

        return $eventData;
    }
}
