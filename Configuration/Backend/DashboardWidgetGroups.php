<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * Widget group for the nr_passkeys_be dashboard widgets. Only read by
 * EXT:dashboard's ServiceProvider — harmless when dashboard is not
 * installed.
 */
return [
    'nrpasskeys' => [
        'title' => 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang_dashboard.xlf:widget_group.nrpasskeys',
    ],
];
