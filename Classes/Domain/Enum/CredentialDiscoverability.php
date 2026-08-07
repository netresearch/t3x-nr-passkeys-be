<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Enum;

/**
 * Whether a credential is stored discoverably (a "resident key") on the
 * authenticator.
 *
 * Only a discoverable credential can be offered in the browser's autofill menu,
 * so this decides whether a passkey participates in conditional UI at all. The
 * answer comes from the credProps extension at registration; authenticators may
 * stay silent, which is why "unknown" is a state of its own — it is not the same
 * as "not discoverable" and must not be reported to the user as a limitation.
 */
enum CredentialDiscoverability: string
{
    case Discoverable = 'discoverable';
    case NotDiscoverable = 'not_discoverable';
    case Unknown = 'unknown';

    /**
     * Map the persisted tinyint column: 1 discoverable, 0 not, NULL unknown.
     */
    public static function fromDatabaseValue(mixed $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return (bool) $value ? self::Discoverable : self::NotDiscoverable;
    }

    /**
     * Map the credProps.rk answer the browser forwards, if it sent one.
     */
    public static function fromClientExtensionResult(mixed $rk): self
    {
        if (!\is_bool($rk)) {
            return self::Unknown;
        }

        return $rk ? self::Discoverable : self::NotDiscoverable;
    }

    /**
     * Column value: null keeps "unknown" distinguishable from "not discoverable".
     */
    public function toDatabaseValue(): ?int
    {
        return match ($this) {
            self::Discoverable => 1,
            self::NotDiscoverable => 0,
            self::Unknown => null,
        };
    }

    /**
     * Tri-state for API responses: true discoverable, false not, null unknown.
     *
     * The client warns only on false. Collapsing "unknown" into false there
     * would tell users their passkey cannot do autofill when nobody ever
     * established that.
     */
    public function toJsonValue(): ?bool
    {
        return match ($this) {
            self::Discoverable => true,
            self::NotDiscoverable => false,
            self::Unknown => null,
        };
    }
}
