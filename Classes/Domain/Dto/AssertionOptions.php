<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Value object wrapping WebAuthn assertion options and challenge token.
 */
final readonly class AssertionOptions
{
    public function __construct(public PublicKeyCredentialRequestOptions $options, public string $challengeToken) {}
}
