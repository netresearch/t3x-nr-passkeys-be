<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Resources;

use DOMDocument;
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
    public function adoptionBarIsANamedNativeProgressWithoutBootstrapProgressClasses(): void
    {
        $html = $this->read('Resources/Private/Templates/AdminModule/Dashboard.html');

        // Core 14.3 has no .progress / .progress-bar rules, so that markup draws no bar there.
        self::assertStringNotContainsString('class="progress', $html);
        self::assertStringNotContainsString('bg-success', $html);

        // The native element carries role, value and name; no div with role="progressbar".
        self::assertStringNotContainsString('role="progressbar"', $html);
        self::assertMatchesRegularExpression(
            '#<progress class="passkey-adoption-meter-bar"\s+max="100"\s+value="\{group\.adoptionPercentage\}"\s+aria-label="[^"]+"></progress>#',
            $html,
        );

        // The name's label key has to exist, or the bar would be named by the bare key.
        self::assertSame(
            1,
            \preg_match(
                '#<progress [^>]*aria-label="\{f:translate\(key: \'([^\']+)\', extensionName: \'NrPasskeysBe\'\)\}: \{group\.title\}"#',
                $html,
                $key,
            ),
        );
        self::assertArrayHasKey($key[1], $this->xliffSources('Resources/Private/Language/locallang.xlf'));

        // The visible percentage stays, hidden from assistive technology (the element announces it).
        self::assertStringContainsString(
            '<span class="passkey-adoption-meter-value" aria-hidden="true"><f:format.number decimals="0">{group.adoptionPercentage}</f:format.number>%</span>',
            $html,
        );
    }

    #[Test]
    public function adoptionBarIsStyledWithCoreTokensInEveryEngine(): void
    {
        $css = $this->read('Resources/Public/Css/backend.css');
        $start = \strpos($css, '.passkey-adoption-meter');
        self::assertNotFalse($start);
        $rules = \substr($css, $start);
        $track = 'background-color: var(--typo3-surface-container-high, var(--bs-secondary-bg));';
        $fill = 'background-color: var(--typo3-component-primary-color);';

        foreach ([
            '.passkey-adoption-meter-bar {' => $track,
            '.passkey-adoption-meter-bar::-webkit-progress-bar {' => $track,
            '.passkey-adoption-meter-bar::-webkit-progress-value {' => $fill,
            '.passkey-adoption-meter-bar::-moz-progress-bar {' => $fill,
        ] as $selector => $declaration) {
            $at = \strpos($rules, $selector);
            self::assertNotFalse($at, $selector);
            $body = \substr($rules, $at, (int) \strpos($rules, '}', $at) - $at);
            self::assertStringContainsString($declaration, $body, $selector);
        }

        self::assertStringContainsString('appearance: none;', $rules);
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
    public function linksInsideHelpTextAreUnderlined(): void
    {
        // Help.html renders these XLIFF sources raw, with the dashboard URL as argument.
        $help = $this->read('Resources/Private/Templates/AdminModule/Help.html');
        \preg_match_all(
            '#<f:translate key="([^"]+)" extensionName="NrPasskeysBe" arguments="\{0: dashboardUrl\}" />#',
            $help,
            $keys,
        );
        self::assertSame(['help.rollout.step3Body', 'help.rollout.step6Body', 'help.faq.answer4'], $keys[1]);

        $sources = [];

        foreach (\glob(self::ROOT . 'Resources/Private/Language/*.xlf') ?: [] as $file) {
            $sources += $this->xliffSources('Resources/Private/Language/' . \basename($file));
        }

        \ksort($sources);
        $withLinks = \array_filter($sources, static fn(string $source): bool => \str_contains($source, '<a '));

        // These three are the only inline links in the extension's texts (sorted by id).
        self::assertSame(
            ['help.faq.answer4', 'help.rollout.step3Body', 'help.rollout.step6Body'],
            \array_keys($withLinks),
        );

        foreach ($withLinks as $id => $source) {
            self::assertMatchesRegularExpression('#<a href="%1\$s" class="text-decoration-underline">#', $source, $id);
        }
    }

    /**
     * @return array<string, string> trans-unit id => source text (entities decoded)
     */
    private function xliffSources(string $path): array
    {
        $xml = new DOMDocument();
        self::assertTrue($xml->loadXML($this->read($path)));
        $sources = [];

        foreach ($xml->getElementsByTagName('trans-unit') as $unit) {
            $source = $unit
                ->getElementsByTagName('source')
                ->item(0);
            $sources[$unit->getAttribute('id')] = $source?->textContent ?? '';
        }

        \ksort($sources);

        return $sources;
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
