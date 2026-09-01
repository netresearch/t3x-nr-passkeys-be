<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use JsonSerializable;

/**
 * Read-only projection of a backend user's passkey registration status.
 *
 * Used by the admin dashboard to list users who have not yet registered passkeys.
 */
final readonly class UserPasskeyStatus implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $username,
        public string $realName,
        public string $groups,
        public int $gracePeriodStart,
        public int $gracePeriodRemainingDays,
        public int $nudgeUntil = 0,
    ) {}

    public function hasActiveNudge(): bool
    {
        return $this->nudgeUntil > \time();
    }

    /**
     * @return array{uid: int, username: string, realName: string, groups: string, gracePeriodStart: int, gracePeriodRemainingDays: int, nudgeUntil: int, hasActiveNudge: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'username' => $this->username,
            'realName' => $this->realName,
            'groups' => $this->groups,
            'gracePeriodStart' => $this->gracePeriodStart,
            'gracePeriodRemainingDays' => $this->gracePeriodRemainingDays,
            'nudgeUntil' => $this->nudgeUntil,
            'hasActiveNudge' => $this->hasActiveNudge(),
        ];
    }
}
