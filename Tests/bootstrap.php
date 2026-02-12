<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap that enables bypass-finals before autoloading.
 *
 * This allows PHPUnit to create mock objects for final classes.
 * The final keyword is only stripped at runtime during tests —
 * production code retains the final declaration.
 */

require __DIR__ . '/../.Build/vendor/autoload.php';

DG\BypassFinals::enable();
