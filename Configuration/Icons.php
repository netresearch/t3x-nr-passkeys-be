<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// monochrome passkey mark that adapts via currentColor. v12/v13 use the
// colored (teal tile) variant that matches the classic module menu.
$passkeyIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? 'EXT:nr_passkeys_be/Resources/Public/Icons/ModuleIcon.svg'
    : 'EXT:nr_passkeys_be/Resources/Public/Icons/ModuleIcon.legacy.svg';

return [
    'passkeys-be-login' => [
        'provider' => SvgIconProvider::class,
        'source' => $passkeyIcon,
    ],
    'passkeys-be-module' => [
        'provider' => SvgIconProvider::class,
        'source' => $passkeyIcon,
    ],
];
