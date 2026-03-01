<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Enum;

/**
 * Per-group passkey enforcement level.
 *
 * Controls how aggressively backend users are required to register and use passkeys.
 */
enum EnforcementLevel: string
{
    case Off = 'off';
    case Encourage = 'encourage';
    case Required = 'required';
    case Enforced = 'enforced';

    /**
     * Numeric severity (0-3) for comparison purposes.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Encourage => 1,
            self::Required => 2,
            self::Enforced => 3,
        };
    }

    /**
     * Returns whichever level has higher severity.
     */
    public static function strictest(self $a, self $b): self
    {
        return $a->severity() >= $b->severity() ? $a : $b;
    }

    /**
     * Whether this level requires the passkey-registration interstitial (severity >= 2).
     */
    public function requiresInterstitial(): bool
    {
        return $this->severity() >= 2;
    }

    /**
     * Whether this level requires the encourage-stage banner (severity >= 1).
     */
    public function requiresBanner(): bool
    {
        return $this->severity() >= 1;
    }
}
