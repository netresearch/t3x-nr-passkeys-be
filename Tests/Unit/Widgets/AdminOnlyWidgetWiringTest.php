<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Widgets;

use Netresearch\NrPasskeysBe\Widgets\AdminOnlyDoughnutChartWidget;
use Netresearch\NrPasskeysBe\Widgets\AdminOnlyNumberWithIconWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;

/**
 * The dashboard's DashboardWidgetPass derives the adminOnly flag from the
 * widget class implementing AdminOnlyWidgetInterface. These tests pin the
 * marker wiring of the extension's widget subclasses so the widgets stay
 * admin-only on TYPO3 v14.3+.
 *
 * Skipped on v12/v13 vendor sets where AdminOnlyWidgetInterface does not
 * exist — there the subclasses are never registered nor loaded (see
 * Configuration/Services.Dashboard.php). No #[CoversClass] on purpose:
 * coverage metadata resolution would autoload the subclasses, which must
 * not happen on v12/v13.
 */
final class AdminOnlyWidgetWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!\interface_exists(AdminOnlyWidgetInterface::class)) {
            self::markTestSkipped('AdminOnlyWidgetInterface requires TYPO3 v14.3+');
        }
    }

    #[Test]
    public function doughnutWidgetIsAdminOnlyAndExtendsCoreWidget(): void
    {
        self::assertTrue(\is_a(AdminOnlyDoughnutChartWidget::class, AdminOnlyWidgetInterface::class, true));
        self::assertTrue(\is_a(AdminOnlyDoughnutChartWidget::class, DoughnutChartWidget::class, true));
    }

    #[Test]
    public function numberWithIconWidgetIsAdminOnlyAndExtendsCoreWidget(): void
    {
        self::assertTrue(\is_a(AdminOnlyNumberWithIconWidget::class, AdminOnlyWidgetInterface::class, true));
        self::assertTrue(\is_a(AdminOnlyNumberWithIconWidget::class, NumberWithIconWidget::class, true));
    }
}
