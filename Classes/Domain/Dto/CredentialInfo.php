<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use JsonSerializable;
use Netresearch\NrPasskeysBe\Domain\Enum\CredentialDiscoverability;

/**
 * Read-only projection of a credential for user-facing API responses.
 */
final readonly class CredentialInfo implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $label,
        public int $createdAt,
        public int $lastUsedAt,
        public bool $isRevoked,
        public CredentialDiscoverability $discoverability = CredentialDiscoverability::Unknown,
    ) {}

    /**
     * @return array{uid: int, label: string, createdAt: int, lastUsedAt: int, isRevoked: bool, discoverable: bool|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'isRevoked' => $this->isRevoked,
            'discoverable' => $this->discoverability->toDatabaseValue() === null
                ? null
                : $this->discoverability === CredentialDiscoverability::Discoverable,
        ];
    }
}
