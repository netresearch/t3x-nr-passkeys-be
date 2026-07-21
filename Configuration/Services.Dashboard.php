<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrPasskeysBe\Widgets\AdminOnlyDoughnutChartWidget;
use Netresearch\NrPasskeysBe\Widgets\AdminOnlyNumberWithIconWidget;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\ActiveCredentialsCountDataProvider;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\Widgets\AdminOnlyWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Dashboard widget registration for nr_passkeys_be.
 *
 * Imported conditionally from Configuration/Services.php only when
 * typo3/cms-dashboard is installed. This is a PHP config file (not YAML)
 * because TYPO3 loads Configuration/Services.php with a standalone Symfony
 * PhpFileLoader that has no YAML loader in its resolver, so a `.yaml`
 * import cannot be resolved from there.
 *
 * Admin-only gating: the dashboard's DashboardWidgetPass derives the
 * adminOnly flag from the widget class implementing
 * AdminOnlyWidgetInterface, which exists since TYPO3 v14.3. On v14.3+ the
 * extension's AdminOnly* widget subclasses are registered so the widgets
 * are hidden from non-admins and cannot be granted to groups — matching
 * the admin-only access level of the extension's backend module. On
 * v12/v13 the plain core widget classes are registered; there, widget
 * visibility follows the core's own pre-14.3 semantics: non-admins only
 * see a widget when a backend group explicitly grants it via the
 * "available_widgets" permission.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->set(PasskeyAdoptionChartDataProvider::class);
    $services->set(ActiveCredentialsCountDataProvider::class);

    $adminOnlySupported = \interface_exists(AdminOnlyWidgetInterface::class);

    $services->set(
        'dashboard.widget.nrpasskeysbe.adoption',
        $adminOnlySupported ? AdminOnlyDoughnutChartWidget::class : DoughnutChartWidget::class,
    )
        ->arg('$dataProvider', service(PasskeyAdoptionChartDataProvider::class))
        ->tag('dashboard.widget', [
            'identifier'     => 'nrpasskeysbe-adoption',
            'groupNames'     => 'nrpasskeys',
            'title'          => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.title',
            'description'    => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.adoption.description',
            'iconIdentifier' => 'passkeys-be-module',
            'height'         => 'medium',
            'width'          => 'small',
        ]);

    $services->set(
        'dashboard.widget.nrpasskeysbe.credentials',
        $adminOnlySupported ? AdminOnlyNumberWithIconWidget::class : NumberWithIconWidget::class,
    )
        ->arg('$dataProvider', service(ActiveCredentialsCountDataProvider::class))
        ->arg('$options', [
            'icon'     => 'passkeys-be-module',
            'title'    => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.title',
            'subtitle' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.subtitle',
        ])
        ->tag('dashboard.widget', [
            'identifier'     => 'nrpasskeysbe-credentials',
            'groupNames'     => 'nrpasskeys',
            'title'          => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.title',
            'description'    => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget.credentials.description',
            'iconIdentifier' => 'passkeys-be-module',
            'height'         => 'small',
            'width'          => 'small',
        ]);
};
