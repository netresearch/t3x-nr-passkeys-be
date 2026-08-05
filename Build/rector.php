<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Ssch\TYPO3Rector\TYPO312\v0\RemoveAddLLrefForTCAdescrMethodCallRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: code-quality sets, rule skips, importNames,
    // phpVersion, and the package's ergebnis-free phpstan-rector.neon.
    $configure($rectorConfig, __DIR__ . '/..');

    // paths() REPLACES the shared list, so repeat it and add Tests/.
    $rectorConfig->paths([
        __DIR__ . '/../Classes',
        __DIR__ . '/../Configuration',
        __DIR__ . '/../Tests',
        __DIR__ . '/../ext_localconf.php',
        __DIR__ . '/../ext_tables.php',
    ]);

    $rectorConfig->skip([
        // ext_tables.php guards addLLrefForTCAdescr() with method_exists so
        // CSH still registers on TYPO3 v12, which this extension supports.
        // The v13 level set would empty that guarded call.
        RemoveAddLLrefForTCAdescrMethodCallRector::class,
        // Every public method here is a framework entry point whose signature IS
        // the contract, so an unused parameter is not dead code. The decisive
        // case: both #[AsEventListener] listeners omit the `event:` argument, so
        // TYPO3 v13/v14 resolves the event from the __invoke() parameter type —
        // dropping it deregisters the listener. The AJAX route actions
        // (Configuration/Backend/AjaxRoutes.php) and the Setup-module
        // callUserFunction() panel are invoked with a request/params argument
        // they must keep declaring.
        RemoveUnusedPublicMethodParameterRector::class,
    ]);

    // TYPO3 migration level: v13, the lowest still-supported major
    // (typo3-ci-workflows#155); raise when v13 support is dropped.
    $rectorConfig->sets([
        Typo3LevelSetList::UP_TO_TYPO3_13,
    ]);
};
