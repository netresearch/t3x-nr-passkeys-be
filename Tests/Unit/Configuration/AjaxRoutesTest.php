<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessLifetime;

/**
 * Guards the Sudo Mode declaration on the backend AJAX routes.
 *
 * RouteDispatcher::assertSudoMode() only challenges when the route carries a
 * `sudoMode` option, so the JS sudoModeInterceptor is inert without it. A write
 * route added without the option would silently accept credential changes on any
 * authenticated session, which is what these assertions prevent.
 */
final class AjaxRoutesTest extends TestCase
{
    /**
     * Route configuration, loaded once per process.
     *
     * The file returns a value, and require_once yields `true` rather than that value
     * on a second load, so it must be included exactly once and the result cached —
     * hence the static. assertIsArray() below turns a violated assumption into a
     * failure here instead of a confusing error further down.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $routes = null;

    /**
     * @return array<string, mixed>
     */
    private function routes(): array
    {
        if (self::$routes === null) {
            $routes = require_once \dirname(__DIR__, 3) . '/Configuration/Backend/AjaxRoutes.php';
            self::assertIsArray($routes, 'AjaxRoutes.php must be included exactly once per process');
            self::$routes = $routes;
        }

        return self::$routes;
    }

    /**
     * @return list<array{string}>
     */
    public static function writeRouteProvider(): array
    {
        return [
            ['passkeys_manage_registration_options'],
            ['passkeys_manage_registration_verify'],
            ['passkeys_manage_rename'],
            ['passkeys_manage_remove'],
            ['passkeys_admin_remove'],
            ['passkeys_admin_unlock'],
            ['passkeys_admin_revoke_all'],
            ['passkeys_admin_update_enforcement'],
            ['passkeys_admin_send_reminder'],
            ['passkeys_admin_clear_nudge'],
        ];
    }

    #[Test]
    #[DataProvider('writeRouteProvider')]
    public function writeRouteRequiresSudoMode(string $identifier): void
    {
        $routes = $this->routes();
        self::assertArrayHasKey($identifier, $routes);
        $route = $routes[$identifier];
        self::assertIsArray($route);
        self::assertArrayHasKey('sudoMode', $route, $identifier . ' must require sudo mode');
        $sudoMode = $route['sudoMode'];
        self::assertIsArray($sudoMode);
        self::assertSame('passkeys', $sudoMode['group'] ?? null);
        self::assertSame(AccessLifetime::medium, $sudoMode['lifetime'] ?? null);
    }

    /**
     * Every POST route is a write route, so the provider above must stay exhaustive:
     * a new POST route without sudo mode fails here even if nobody updates the list.
     */
    #[Test]
    public function everyPostRouteRequiresSudoMode(): void
    {
        $missing = [];

        foreach ($this->routes() as $identifier => $route) {
            if (!\is_array($route)) {
                continue;
            }

            if (!\in_array('POST', (array) ($route['methods'] ?? []), true)) {
                continue;
            }

            if (!\is_array($route['sudoMode'] ?? null)) {
                $missing[] = (string) $identifier;
            }
        }

        self::assertSame([], $missing, 'POST routes without a sudoMode option: ' . \implode(', ', $missing));
    }

    /**
     * Read-only routes are deliberately not gated: a sudo-mode prompt on a list
     * request would fire on every panel render.
     */
    #[Test]
    public function readRoutesAreNotGated(): void
    {
        $routes = $this->routes();

        foreach (['passkeys_manage_list', 'passkeys_admin_list', 'passkeys_enforcement_status'] as $identifier) {
            self::assertArrayHasKey($identifier, $routes);
            $route = $routes[$identifier];
            self::assertIsArray($route);
            self::assertArrayNotHasKey('sudoMode', $route);
        }
    }
}
