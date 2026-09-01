<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);
use DG\BypassFinals;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * PHPUnit bootstrap that enables bypass-finals before autoloading.
 *
 * This allows PHPUnit to create mock objects for final classes.
 * The final keyword is only stripped at runtime during tests —
 * production code retains the final declaration.
 */
// TYPO3 v12 core classes (e.g. PageRenderer) reference the LF constant
// which is normally defined by SystemEnvironmentBuilder::defineBaseConstants().
// In unit tests the TYPO3 bootstrap does not run, so we define it here.
if (!\defined('LF')) {
    \define('LF', "\n");
}

require __DIR__ . '/../.Build/vendor/autoload.php';
BypassFinals::enable();

// NormalizedParams::createFromServerParams() reads Environment::getCurrentScript()
// and getPublicPath(); initialize Environment so the fallback path works in tests.
$projectPath = \dirname(__DIR__);
Environment::initialize(
    new ApplicationContext('Testing'),
    true,
    true,
    $projectPath,
    $projectPath,
    $projectPath . '/var',
    $projectPath . '/config',
    $projectPath . '/bootstrap.php',
    'UNIX',
);
