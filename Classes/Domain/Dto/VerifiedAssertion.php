<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Webauthn\CredentialRecord;

/**
 * Value object wrapping a verified WebAuthn assertion result.
 */
final readonly class VerifiedAssertion
{
    public function __construct(
        public Credential $credential,
        public CredentialRecord $source,
    ) {}
}
