<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Authentication;

use Netresearch\NrPasskeysBe\Authentication\PasskeyLoginProofStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(PasskeyLoginProofStore::class)]
final class PasskeyLoginProofStoreTest extends UnitTestCase
{
    #[Test]
    public function takeReturnsTheRecordedProofOnce(): void
    {
        $store = new PasskeyLoginProofStore();
        $user  = $this->createStub(BackendUserAuthentication::class);

        $store->record($user, 5, true);

        self::assertSame(['uid' => 5, 'creditMfa' => true], $store->take($user));
        self::assertNull($store->take($user));
    }

    #[Test]
    public function proofsAreKeptPerUserObject(): void
    {
        $store  = new PasskeyLoginProofStore();
        $first  = $this->createStub(BackendUserAuthentication::class);
        $second = $this->createStub(BackendUserAuthentication::class);

        $store->record($first, 5, false);

        self::assertNull($store->take($second));
        self::assertSame(['uid' => 5, 'creditMfa' => false], $store->take($first));
    }

    #[Test]
    public function aLaterRecordReplacesTheEarlierOne(): void
    {
        $store = new PasskeyLoginProofStore();
        $user  = $this->createStub(BackendUserAuthentication::class);

        $store->record($user, 5, false);
        $store->record($user, 5, true);

        self::assertSame(['uid' => 5, 'creditMfa' => true], $store->take($user));
    }
}
