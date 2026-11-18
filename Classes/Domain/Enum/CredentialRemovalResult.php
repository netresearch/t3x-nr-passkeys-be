<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Enum;

enum CredentialRemovalResult
{
    case Removed;
    case NotFound;
    case LastKey;
}
