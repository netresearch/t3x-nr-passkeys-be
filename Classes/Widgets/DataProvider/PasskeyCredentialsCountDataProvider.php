<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\DataProvider;

use Netresearch\NrPasskeysBe\Widgets\Adoption\PasskeyAdoptionStatsProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Total active (non-revoked) passkey credentials across all audience segments
 * (backend always; frontend when nr_passkeys_fe is present). Credentials are
 * homogeneous, so a combined count is meaningful; the segment breakdown is
 * carried by the widget subtitle. Degrades to backend-only automatically.
 */
final readonly class PasskeyCredentialsCountDataProvider implements NumberWithIconDataProviderInterface
{
    /**
     * @param iterable<PasskeyAdoptionStatsProviderInterface> $statsProviders
     */
    public function __construct(private iterable $statsProviders) {}

    public function getNumber(): int
    {
        $total = 0;

        foreach ($this->statsProviders as $provider) {
            $total += $provider->getAudienceStats()->activeCredentials;
        }

        return $total;
    }
}
