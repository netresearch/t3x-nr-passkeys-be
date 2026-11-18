<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Netresearch\NrPasskeysBe\Service\DatabaseRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(DatabaseRateLimitStore::class)]
final class DatabaseRateLimitStoreTest extends TestCase
{
    #[Test]
    public function persistentContentionPropagatesAfterThreeTransactions(): void
    {
        $error      = new class extends RuntimeException implements RetryableException {};
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(3))
            ->method('transactional')
            ->willThrowException($error);
        $pool = $this->createStub(ConnectionPool::class);
        $pool
            ->method('getConnectionForTable')
            ->willReturn($connection);
        $this->expectExceptionObject($error);
        (new DatabaseRateLimitStore($pool))->increment('test-key', 'test-group', 30, 1);
    }
}
