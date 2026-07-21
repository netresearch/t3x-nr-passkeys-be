<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Data provider for the "active passkey credentials" NumberWithIcon widget.
 *
 * Returns the total number of active (non-deleted, non-revoked) backend
 * passkey credentials via a single aggregate query in AdoptionStatsService.
 */
final class ActiveCredentialsCountDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private readonly AdoptionStatsService $adoptionStatsService,
    ) {}

    public function getNumber(): int
    {
        return $this->adoptionStatsService->countActiveCredentials();
    }
}
