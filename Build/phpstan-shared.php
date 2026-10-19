<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

// Includes the shared PHPStan config of netresearch/typo3-ci-workflows when it
// is installed, and the TYPO3 v12 fallback when it is not. The shared package
// requires saschaegerer/phpstan-typo3 ^2 || ^3, which needs typo3/cms-core
// ^13.4.3, so CI removes it on the TYPO3 12.4 cells (remove-dev-deps in
// .github/workflows/ci.yml) and an unconditional include would abort PHPStan
// there. Delete this file and phpstan-typo3-12.neon together with the TYPO3
// 12.4 support, and include the shared file from phpstan.neon directly.
$shared = __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/phpstan/phpstan.neon';

return ['includes' => [\is_file($shared) ? $shared : __DIR__ . '/phpstan-typo3-12.neon']];
