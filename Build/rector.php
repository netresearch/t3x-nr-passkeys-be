<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Ssch\TYPO3Rector\TYPO312\v0\RemoveAddLLrefForTCAdescrMethodCallRector;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (with Tests/), code-quality sets, rule
    // skips, the package's ergebnis-free phpstan-rector.neon, and the PHP and
    // TYPO3 level sets for the lowest supported versions (composer.json:
    // php ^8.2, typo3/cms-core ^12.4 || ^13.4 || ^14.3).
    $configure($rectorConfig, __DIR__ . '/..', ['tests' => true, 'php' => '8.2', 'typo3' => 12]);

    $rectorConfig->skip(
        [
            // ext_tables.php guards addLLrefForTCAdescr() with method_exists so
            // CSH still registers on TYPO3 v12, which this extension supports.
            // The TYPO3 level set would empty that guarded call.
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
        ],
    );
};
