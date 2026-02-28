<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;

/**
 * Read-only snapshot of a user's passkey enforcement status.
 *
 * Combines the effective enforcement level with grace-period tracking
 * and passkey ownership to drive interstitial and skip logic.
 */
final readonly class EnforcementStatus
{
    /**
     * @param EnforcementLevel $level          Effective enforcement level (strictest across groups)
     * @param int              $gracePeriodDays Total grace-period length in days
     * @param int              $gracePeriodStart Unix timestamp when grace period started (0 = not started)
     * @param bool             $hasPasskeys     Whether the user has registered passkeys
     */
    public function __construct(
        public EnforcementLevel $level,
        public int $gracePeriodDays,
        public int $gracePeriodStart,
        public bool $hasPasskeys,
    ) {}

    /**
     * Number of days remaining in the grace period.
     *
     * Returns the full grace-period length if not yet started (start = 0).
     * Never returns negative values.
     *
     * @param int|null $currentTime Unix timestamp to use as "now" (defaults to \time())
     */
    public function gracePeriodRemainingDays(?int $currentTime = null): int
    {
        if ($this->gracePeriodStart === 0) {
            return $this->gracePeriodDays;
        }

        $elapsedSeconds = ($currentTime ?? \time()) - $this->gracePeriodStart;
        $elapsedDays = (int) \floor($elapsedSeconds / 86_400);
        $remaining = $this->gracePeriodDays - $elapsedDays;

        return \max(0, $remaining);
    }

    /**
     * Whether the grace period has expired.
     *
     * Always false if the grace period has not been started (start = 0).
     *
     * @param int|null $currentTime Unix timestamp to use as "now" (defaults to \time())
     */
    public function isGracePeriodExpired(?int $currentTime = null): bool
    {
        if ($this->gracePeriodStart === 0) {
            return false;
        }

        return $this->gracePeriodRemainingDays($currentTime) === 0;
    }

    /**
     * Whether the passkey-registration interstitial should be shown.
     *
     * False if the user already has passkeys. Otherwise delegates to the level.
     */
    public function requiresInterstitial(): bool
    {
        if ($this->hasPasskeys) {
            return false;
        }

        return $this->level->requiresInterstitial();
    }

    /**
     * Whether the user can skip (dismiss) the interstitial.
     *
     * - Enforced: never skippable
     * - Required with expired grace period: not skippable
     * - Everything else: skippable
     *
     * @param int|null $currentTime Unix timestamp to use as "now" (defaults to \time())
     */
    public function canSkip(?int $currentTime = null): bool
    {
        if ($this->level === EnforcementLevel::Enforced) {
            return false;
        }

        if ($this->level === EnforcementLevel::Required && $this->isGracePeriodExpired($currentTime)) {
            return false;
        }

        return true;
    }
}
