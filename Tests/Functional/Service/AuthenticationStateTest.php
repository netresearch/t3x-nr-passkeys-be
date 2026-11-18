<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Service;

use Netresearch\NrPasskeysBe\Service\ConsumedTokenStore;
use Netresearch\NrPasskeysBe\Service\DatabaseRateLimitStore;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AuthenticationStateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    #[Test]
    public function aTicketHasOneClaimAcrossIndependentInstances(): void
    {
        $db     = $this->get(ConnectionPool::class);
        $first  = new ConsumedTokenStore($db);
        $second = new ConsumedTokenStore($db);
        self::assertTrue($first->claim('login:example', time() + 120));
        self::assertFalse($second->claim('login:example', time() + 120));
        self::assertFalse($second->claim('login:expired', time() - 1));
        self::assertTrue($second->claim('nonce:example', time() + 120));
    }

    #[Test]
    public function theBudgetAndResetAreSharedBetweenWorkers(): void
    {
        $db     = $this->get(ConnectionPool::class);
        $first  = new DatabaseRateLimitStore($db);
        $second = new DatabaseRateLimitStore($db);
        $key    = hash('sha256', 'account');
        $group  = hash('sha256', 'group');
        self::assertTrue($first->increment($key, $group, 30, 1));
        self::assertFalse($second->increment($key, $group, 30, 1));
        self::assertSame(1, $second->count($key));
        $first->forgetGroup($group);
        self::assertSame(0, $second->count($key));
    }
}
