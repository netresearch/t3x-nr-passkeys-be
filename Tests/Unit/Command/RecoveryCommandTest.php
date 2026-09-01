<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Command;

use Netresearch\NrPasskeysBe\Command\RecoveryCommand;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(RecoveryCommand::class)]
final class RecoveryCommandTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;

    private RateLimiterService&MockObject $rateLimiterService;

    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->rateLimiterService = $this->createMock(RateLimiterService::class);
        $this->tester = new CommandTester(new RecoveryCommand($this->connectionPool, $this->rateLimiterService));
    }

    #[Test]
    public function unlockResetsTheLockoutForTheGivenUsername(): void
    {
        $this->rateLimiterService
            ->expects(self::once())
            ->method('resetLockout')
            ->with('admin');
        $exitCode = $this->tester->execute(['--unlock' => 'admin']);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Login lockout reset', $this->tester->getDisplay());
    }

    #[Test]
    public function returnsInvalidWhenNoActionRequested(): void
    {
        $this->rateLimiterService
            ->expects(self::never())
            ->method('resetLockout');
        $exitCode = $this->tester->execute([]);
        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Nothing to do', $this->tester->getDisplay());
    }
}
