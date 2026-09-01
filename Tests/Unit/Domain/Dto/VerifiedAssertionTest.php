<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webauthn\CredentialRecord;

#[CoversClass(VerifiedAssertion::class)]
final class VerifiedAssertionTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $credential = new Credential(uid: 1, beUser: 42, label: 'Test Key');
        $source = $this->createMock(CredentialRecord::class);
        $dto = new VerifiedAssertion(credential: $credential, source: $source);
        self::assertSame($credential, $dto->credential);
        self::assertSame($source, $dto->source);
    }
}
