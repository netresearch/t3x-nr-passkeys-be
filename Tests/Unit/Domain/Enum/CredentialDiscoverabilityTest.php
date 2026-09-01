<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Enum;

use Netresearch\NrPasskeysBe\Domain\Enum\CredentialDiscoverability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of this enum is that "unknown" is a third state and not a
 * synonym for "not discoverable": an authenticator may decline to report, and
 * every credential registered before credProps was requested has no answer at
 * all. Reporting those as non-discoverable would tell users their passkey
 * cannot do autofill when nobody ever established that.
 */
#[CoversClass(CredentialDiscoverability::class)]
final class CredentialDiscoverabilityTest extends TestCase
{
    /**
     * @return list<array{mixed, CredentialDiscoverability}>
     */
    public static function databaseValueProvider(): array
    {
        return [
            [null, CredentialDiscoverability::Unknown],
            ['', CredentialDiscoverability::Unknown],
            [1, CredentialDiscoverability::Discoverable],
            ['1', CredentialDiscoverability::Discoverable],
            [0, CredentialDiscoverability::NotDiscoverable],
            ['0', CredentialDiscoverability::NotDiscoverable],
        ];
    }

    #[Test]
    #[DataProvider('databaseValueProvider')]
    public function fromDatabaseValueMapsTheColumn(mixed $column, CredentialDiscoverability $expected): void
    {
        self::assertSame($expected, CredentialDiscoverability::fromDatabaseValue($column));
    }

    /**
     * @return list<array{mixed, CredentialDiscoverability}>
     */
    public static function clientExtensionProvider(): array
    {
        return [
            [true, CredentialDiscoverability::Discoverable],
            [false, CredentialDiscoverability::NotDiscoverable],
            // Everything that is not a boolean means the authenticator said
            // nothing usable — including a missing key, which arrives as null.
            [null, CredentialDiscoverability::Unknown],
            ['true', CredentialDiscoverability::Unknown],
            [1, CredentialDiscoverability::Unknown],
            [[], CredentialDiscoverability::Unknown],
        ];
    }

    #[Test]
    #[DataProvider('clientExtensionProvider')]
    public function fromClientExtensionResultAcceptsOnlyABoolean(mixed $rk, CredentialDiscoverability $expected): void
    {
        self::assertSame($expected, CredentialDiscoverability::fromClientExtensionResult($rk));
    }

    #[Test]
    public function everyStateRoundTripsThroughTheColumn(): void
    {
        foreach (CredentialDiscoverability::cases() as $case) {
            self::assertSame(
                $case,
                CredentialDiscoverability::fromDatabaseValue($case->toDatabaseValue()),
                $case->value . ' does not survive a write/read cycle',
            );
        }
    }

    #[Test]
    public function unknownIsNullInBothProjectionsAndNeverFalse(): void
    {
        // The distinction this enum exists for: a client that warns on false
        // must not be handed false for a credential nobody asked about.
        self::assertNull(CredentialDiscoverability::Unknown->toDatabaseValue());
        self::assertNull(CredentialDiscoverability::Unknown->toJsonValue());
        self::assertNotFalse(CredentialDiscoverability::Unknown->toJsonValue());
    }

    #[Test]
    public function jsonProjectionSeparatesTheTwoKnownStates(): void
    {
        self::assertTrue(CredentialDiscoverability::Discoverable->toJsonValue());
        self::assertFalse(CredentialDiscoverability::NotDiscoverable->toJsonValue());
    }
}
