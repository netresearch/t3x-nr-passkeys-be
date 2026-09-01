<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets;

use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;

/**
 * Admin-only variant of the core doughnut chart widget.
 *
 * The dashboard's DashboardWidgetPass derives the adminOnly flag solely
 * from the widget CLASS implementing AdminOnlyWidgetInterface, so using
 * the core widget class directly cannot express admin-only. This thin
 * subclass adds the marker interface and nothing else.
 *
 * AdminOnlyWidgetInterface exists since TYPO3 v14.3 only. This class is
 * therefore referenced exclusively from the guarded
 * Configuration/Services.Dashboard.php, which falls back to the plain core
 * widget class on v12/v13 (where widget visibility is governed by the
 * "available_widgets" backend group permission instead) and is excluded
 * from the Services.yaml class scan.
 */
final class AdminOnlyDoughnutChartWidget extends DoughnutChartWidget implements AdminOnlyWidgetInterface {}
