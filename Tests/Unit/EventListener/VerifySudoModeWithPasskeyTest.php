<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysBe\EventListener\VerifySudoModeWithPasskey;
use Netresearch\NrPasskeysBe\Service\SudoModeTokenService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessClaim;
use TYPO3\CMS\Backend\Security\SudoMode\Access\ServerRequestInstruction;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeVerifyEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Session\UserSession;

use function str_starts_with;
use function time;

#[CoversClass(VerifySudoModeWithPasskey::class)]
final class VerifySudoModeWithPasskeyTest extends TestCase
{
    private SudoModeTokenService&MockObject $tokenService;

    private VerifySudoModeWithPasskey $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = $this->createMock(SudoModeTokenService::class);
        $this->tokenService
            ->method('isToken')
            ->willReturnCallback(
                static fn (string $value): bool => str_starts_with($value, SudoModeTokenService::PREFIX),
            );
        $this->subject = new VerifySudoModeWithPasskey($this->tokenService);

        $backendUser       = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 7, 'username' => 'editor'];
        $session           = $this->createMock(UserSession::class);
        $session
            ->method('getIdentifier')
            ->willReturn('session-a');
        $backendUser
            ->method('getSession')
            ->willReturn($session);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function event(string $password, bool $installTool = false): SudoModeVerifyEvent
    {
        $claim = new AccessClaim($this->createMock(ServerRequestInstruction::class), time() + 300, null, 'claim-1');

        return new SudoModeVerifyEvent($claim, $password, $installTool);
    }

    #[Test]
    public function aValidTokenVerifiesTheClaim(): void
    {
        $this->tokenService
            ->expects(self::once())
            ->method('verify')
            ->with(SudoModeTokenService::PREFIX . 'x', 7, 'session-a', 'claim-1')
            ->willReturn(true);

        $event = $this->event(SudoModeTokenService::PREFIX . 'x');
        ($this->subject)($event);

        self::assertTrue($event->isVerified());
    }

    #[Test]
    public function anInvalidTokenLeavesTheClaimUnverified(): void
    {
        $this->tokenService
            ->method('verify')
            ->willReturn(false);

        $event = $this->event(SudoModeTokenService::PREFIX . 'x');
        ($this->subject)($event);

        self::assertFalse($event->isVerified());
    }

    #[Test]
    public function aPasswordIsLeftToTheCore(): void
    {
        $this->tokenService
            ->expects(self::never())
            ->method('verify');

        $event = $this->event('my password');
        ($this->subject)($event);

        self::assertFalse($event->isVerified());
    }

    #[Test]
    public function theInstallToolPasswordModeIsNeverTouched(): void
    {
        $this->tokenService
            ->expects(self::never())
            ->method('verify');

        $event = $this->event(SudoModeTokenService::PREFIX . 'x', true);
        ($this->subject)($event);

        self::assertFalse($event->isVerified());
    }

    #[Test]
    public function withoutABackendUserNothingIsVerified(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->tokenService
            ->expects(self::never())
            ->method('verify');

        $event = $this->event(SudoModeTokenService::PREFIX . 'x');
        ($this->subject)($event);

        self::assertFalse($event->isVerified());
    }
}
