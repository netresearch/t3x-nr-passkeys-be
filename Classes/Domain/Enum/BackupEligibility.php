<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Enum;

/** Backup eligibility recorded by WebAuthn during registration. */
enum BackupEligibility
{
    case Eligible;
    case DeviceBound;
    case Unknown;

    /** Accept the authenticated flag or its database representation. */
    public static function fromValue(mixed $value): self
    {
        return match ($value) {
            true, 1, '1'  => self::Eligible,
            false, 0, '0' => self::DeviceBound,
            default       => self::Unknown,
        };
    }

    public function toDatabaseValue(): ?int
    {
        return match ($this) {
            self::Eligible    => 1,
            self::DeviceBound => 0,
            self::Unknown     => null,
        };
    }

    public function toFlag(): ?bool
    {
        return match ($this) {
            self::Eligible    => true,
            self::DeviceBound => false,
            self::Unknown     => null,
        };
    }
}
