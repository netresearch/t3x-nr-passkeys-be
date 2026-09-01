<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Utility;

/**
 * Safe type coercion helpers for mixed values from configuration and database rows.
 */
trait TypeCastTrait
{
    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_numeric($value) ? (int) $value : $default;
    }

    private static function stringVal(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }
}
