<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the admin module markup to classes and tokens TYPO3's backend.css
 * defines at 12.4, 13.4 and 14.3, so the module follows the backend's light
 * and dark scheme instead of Bootstrap classes core does not ship.
 */
#[CoversNothing]
final class BackendThemeMarkupTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private function read(string $path): string
    {
        $content = \file_get_contents(self::ROOT . $path);
        self::assertIsString($content, $path);

        return $content;
    }

    #[Test]
    public function adoptionMeterIsANamedProgressbarWithoutBootstrapProgressClasses(): void
    {
        $html = $this->read('Resources/Private/Templates/AdminModule/Dashboard.html');

        // Core 14.3 has no .progress / .progress-bar rules, so that markup draws no bar there.
        self::assertStringNotContainsString('class="progress', $html);
        self::assertStringNotContainsString('bg-success', $html);
        self::assertMatchesRegularExpression(
            '#class="passkey-adoption-meter-track"\s+role="progressbar"\s+aria-label="[^"]+"\s+aria-valuenow="\{group\.adoptionPercentage\}"\s+aria-valuemin="0"\s+aria-valuemax="100"#',
            $html,
        );

        // The visible percentage stays.
        self::assertStringContainsString(
            '<span class="passkey-adoption-meter-value"><f:format.number decimals="0">{group.adoptionPercentage}</f:format.number>%</span>',
            $html,
        );
    }

    #[Test]
    public function adoptionMeterIsStyledWithCoreTokensOnly(): void
    {
        $css = $this->read('Resources/Public/Css/backend.css');
        $start = \strpos($css, '.passkey-adoption-meter');
        self::assertNotFalse($start);
        $rules = \substr($css, $start);

        self::assertStringContainsString('background-color: var(--typo3-surface-container-high', $rules);
        self::assertStringContainsString('background-color: var(--typo3-component-primary-color)', $rules);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $rules);
        self::assertStringNotContainsString('prefers-color-scheme', $css);
    }

    #[Test]
    public function adminModuleLoadsTheStylesheetThatCarriesTheMeterRules(): void
    {
        $php = $this->read('Classes/Controller/AdminModuleController.php');

        self::assertStringContainsString("addCssFile('EXT:nr_passkeys_be/Resources/Public/Css/backend.css')", $php);
    }

    #[Test]
    public function linksInsideCalloutTextAreUnderlined(): void
    {
        $html = $this->read('Resources/Private/Templates/AdminModule/Dashboard.html');

        \preg_match_all('#<a href="\{helpUrl\}"[^>]*>#', $html, $links);
        self::assertCount(2, $links[0]);

        foreach ($links[0] as $link) {
            self::assertStringContainsString('class="text-decoration-underline"', $link);
        }
    }

    #[Test]
    public function mutedTextUsesTheCoreClass(): void
    {
        foreach ([
            'Resources/Private/Templates/AdminModule/Dashboard.html',
            'Classes/Form/Element/PasskeyInfoElement.php',
            'Classes/UserSettings/PasskeySettingsPanel.php',
        ] as $path) {
            self::assertStringNotContainsString('text-body-secondary', $this->read($path), $path);
        }
    }

    #[Test]
    public function helpTableUsesCoreTableSizeAndScopedHeaders(): void
    {
        $html = $this->read('Resources/Private/Templates/AdminModule/Help.html');

        self::assertStringNotContainsString('table-sm', $html);
        self::assertStringContainsString('<table class="table table-bordered">', $html);
        self::assertSame(0, \preg_match('#<th>#', $html));
    }

    #[Test]
    public function loginHelpBoxUsesAnAlertVariantCoreDefines(): void
    {
        $js = $this->read('Resources/Public/JavaScript/PasskeyLogin.js');

        self::assertStringNotContainsString('alert-light', $js);
        self::assertStringContainsString("helpContent.className = 'alert alert-notice small d-none mb-2';", $js);
    }
}
