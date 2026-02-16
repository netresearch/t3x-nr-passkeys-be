<?php

declare(strict_types=1);

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

DG\BypassFinals::enable();
