<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\AuthenticatedUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticatedUser::class)]
final class AuthenticatedUserTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $user = new AuthenticatedUser(uid: 42, username: 'admin', realName: 'Admin User', isAdmin: true);
        self::assertSame(42, $user->uid);
        self::assertSame('admin', $user->username);
        self::assertSame('Admin User', $user->realName);
        self::assertTrue($user->isAdmin);
    }

    #[Test]
    public function constructorWithNonAdminUser(): void
    {
        $user = new AuthenticatedUser(uid: 7, username: 'editor', realName: '', isAdmin: false);
        self::assertSame(7, $user->uid);
        self::assertSame('editor', $user->username);
        self::assertSame('', $user->realName);
        self::assertFalse($user->isAdmin);
    }
}
