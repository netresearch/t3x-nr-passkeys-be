<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPasskeyStatus::class)]
final class UserPasskeyStatusTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $status = new UserPasskeyStatus(
            uid: 42,
            username: 'jdoe',
            realName: 'John Doe',
            groups: '1,3,5',
            gracePeriodStart: 1_700_000_000,
            gracePeriodRemainingDays: 7,
        );

        self::assertSame(42, $status->uid);
        self::assertSame('jdoe', $status->username);
        self::assertSame('John Doe', $status->realName);
        self::assertSame('1,3,5', $status->groups);
        self::assertSame(1_700_000_000, $status->gracePeriodStart);
        self::assertSame(7, $status->gracePeriodRemainingDays);
        self::assertSame(0, $status->nudgeUntil);
        self::assertFalse($status->hasActiveNudge());
    }

    #[Test]
    public function jsonSerializeReturnsExpectedArray(): void
    {
        $status = new UserPasskeyStatus(
            uid: 99,
            username: 'admin',
            realName: 'Admin User',
            groups: '2,4',
            gracePeriodStart: 1_700_000_000,
            gracePeriodRemainingDays: 3,
        );

        $serialized = $status->jsonSerialize();

        self::assertSame(99, $serialized['uid']);
        self::assertSame('admin', $serialized['username']);
        self::assertSame('Admin User', $serialized['realName']);
        self::assertSame('2,4', $serialized['groups']);
        self::assertSame(1_700_000_000, $serialized['gracePeriodStart']);
        self::assertSame(3, $serialized['gracePeriodRemainingDays']);
        self::assertSame(0, $serialized['nudgeUntil']);
        self::assertFalse($serialized['hasActiveNudge']);
    }

    #[Test]
    public function jsonSerializeHandlesEmptyGroups(): void
    {
        $status = new UserPasskeyStatus(
            uid: 1,
            username: 'nogroup',
            realName: 'No Group User',
            groups: '',
            gracePeriodStart: 0,
            gracePeriodRemainingDays: 0,
        );

        $serialized = $status->jsonSerialize();

        self::assertSame('', $serialized['groups']);
        self::assertSame(0, $serialized['gracePeriodStart']);
        self::assertSame(0, $serialized['gracePeriodRemainingDays']);
    }

    #[Test]
    public function hasActiveNudgeReturnsTrueWhenNudgeInFuture(): void
    {
        $status = new UserPasskeyStatus(
            uid: 42,
            username: 'editor',
            realName: 'Editor',
            groups: '1',
            gracePeriodStart: 0,
            gracePeriodRemainingDays: 0,
            nudgeUntil: \time() + 86_400,
        );

        self::assertTrue($status->hasActiveNudge());
        self::assertTrue($status->jsonSerialize()['hasActiveNudge']);
        self::assertGreaterThan(0, $status->jsonSerialize()['nudgeUntil']);
    }

    #[Test]
    public function hasActiveNudgeReturnsFalseWhenNudgeExpired(): void
    {
        $status = new UserPasskeyStatus(
            uid: 42,
            username: 'editor',
            realName: 'Editor',
            groups: '1',
            gracePeriodStart: 0,
            gracePeriodRemainingDays: 0,
            nudgeUntil: \time() - 86_400,
        );

        self::assertFalse($status->hasActiveNudge());
        self::assertFalse($status->jsonSerialize()['hasActiveNudge']);
    }

    #[Test]
    public function jsonEncodeProducesValidJson(): void
    {
        $status = new UserPasskeyStatus(
            uid: 50,
            username: 'testuser',
            realName: 'Test User',
            groups: '1',
            gracePeriodStart: 1_700_000_000,
            gracePeriodRemainingDays: 14,
        );

        $json = \json_encode($status, \JSON_THROW_ON_ERROR);
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(50, $decoded['uid']);
        self::assertSame('testuser', $decoded['username']);
        self::assertSame(14, $decoded['gracePeriodRemainingDays']);
    }
}
