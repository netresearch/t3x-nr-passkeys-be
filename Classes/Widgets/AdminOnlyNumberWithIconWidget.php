<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets;

use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;

/**
 * Admin-only variant of the core number-with-icon widget.
 *
 * See AdminOnlyDoughnutChartWidget for the rationale: the dashboard's
 * DashboardWidgetPass derives adminOnly from the widget class implementing
 * AdminOnlyWidgetInterface (TYPO3 v14.3+), so core classes cannot express
 * it directly. Referenced only from the guarded
 * Configuration/Services.Dashboard.php.
 */
final class AdminOnlyNumberWithIconWidget extends NumberWithIconWidget implements AdminOnlyWidgetInterface {}
