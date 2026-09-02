<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;

/**
 * Guards the cache backend defaults registered in ext_localconf.php.
 *
 * The nonce cache holds challenge nonces and single-use login tokens, and a login
 * token authenticates a backend user. SimpleFileBackend discards the lifetime
 * passed to set(), never checks expiry in get() and has an empty collectGarbage(),
 * so a default of that class silently turned a 120-second credential into a
 * permanent one and let the token files accumulate without bound.
 *
 * Runs in separate processes: loading ext_localconf.php needs the TYPO3 constant
 * defined and writes to $GLOBALS, neither of which should leak into other tests.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExtLocalconfCacheTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function loadCacheConfiguration(): array
    {
        if (!\defined('TYPO3')) {
            \define('TYPO3', true);
        }

        $GLOBALS['TYPO3_CONF_VARS'] = [];

        // ext_localconf.php registers the auth service as a side effect; only the
        // resulting cache configuration matters here. Each test method runs in its own
        // process (see the class attributes), so a single include per process is
        // enough and require_once cannot swallow a needed re-execution.
        require_once \dirname(__DIR__, 3) . '/ext_localconf.php';
        $caching = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? null;
        self::assertIsArray($caching);

        return $caching;
    }

    #[Test]
    public function nonceCacheDefaultsToATtlHonouringBackend(): void
    {
        $caching = $this->loadCacheConfiguration();
        self::assertArrayHasKey('nr_passkeys_be_nonce', $caching);
        $nonce = $caching['nr_passkeys_be_nonce'];
        self::assertIsArray($nonce);
        self::assertSame(FileBackend::class, $nonce['backend'] ?? null);
        self::assertNotSame(
            SimpleFileBackend::class,
            $nonce['backend'] ?? null,
            'SimpleFileBackend ignores the requested lifetime and never garbage-collects',
        );
    }

    #[Test]
    public function nonceCacheBackendEnforcesExpiryAndCollectsGarbage(): void
    {
        $caching = $this->loadCacheConfiguration();
        $nonce = $caching['nr_passkeys_be_nonce'];
        self::assertIsArray($nonce);
        $backend = $nonce['backend'] ?? null;
        self::assertIsString($backend);

        // The chosen backend must implement expiry itself rather than inheriting
        // SimpleFileBackend's no-op behaviour.
        self::assertNotSame(
            SimpleFileBackend::class,
            (new ReflectionMethod($backend, 'collectGarbage'))
                ->getDeclaringClass()
                ->getName(),
            $backend . "::collectGarbage() must not be SimpleFileBackend's empty implementation",
        );
        self::assertNotSame(
            SimpleFileBackend::class,
            (new ReflectionMethod($backend, 'get'))
                ->getDeclaringClass()
                ->getName(),
            $backend . '::get() must check expiry',
        );
    }

    #[Test]
    public function nonceCacheKeepsItsDefaultLifetime(): void
    {
        $caching = $this->loadCacheConfiguration();
        $nonce = $caching['nr_passkeys_be_nonce'];
        self::assertIsArray($nonce);
        $options = $nonce['options'] ?? null;
        self::assertIsArray($options);
        self::assertSame(300, $options['defaultLifetime'] ?? null);
    }
}
