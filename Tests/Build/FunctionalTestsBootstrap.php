<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use DG\BypassFinals;
use TYPO3\TestingFramework\Core\Testbase;

(static function (): void {
    $testbase = new Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();

// Strip the "final" keyword at runtime so PHPUnit can double classes like
// WebAuthnService (final for production safety) in functional tests. This
// matches the unit-test bootstrap behaviour.
BypassFinals::enable();
