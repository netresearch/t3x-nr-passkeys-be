<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Widgets\Adoption;

use Netresearch\NrPasskeysBe\Domain\Dto\PasskeyAudienceStats;

/**
 * Contributes one audience segment to the unified passkey-adoption dashboard
 * widgets. nr_passkeys_be defines and iterates this; nr_passkeys_be registers
 * the backend (be_users) provider, nr_passkeys_fe registers the frontend
 * (fe_users) provider. Collected via the DI tag
 * 'nr_passkeys_be.adoption_stats_provider'.
 *
 * Placed under Classes/Widgets/ (dashboard-adjacent) but references no
 * typo3/cms-dashboard symbol, so it is safe to autoload even when the
 * dashboard is absent.
 */
interface PasskeyAdoptionStatsProviderInterface
{
    public function getAudienceStats(): PasskeyAudienceStats;
}
