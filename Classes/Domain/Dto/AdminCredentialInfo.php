<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use JsonSerializable;

/**
 * Read-only projection of a credential for admin API responses.
 *
 * Includes revocation details not exposed to regular users.
 */
final readonly class AdminCredentialInfo implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $label,
        public int $createdAt,
        public int $lastUsedAt,
        public bool $isRevoked,
        public int $revokedAt,
        public int $revokedBy,
    ) {}

    /**
     * @return array{uid: int, label: string, createdAt: int, lastUsedAt: int, isRevoked: bool, revokedAt: int, revokedBy: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'isRevoked' => $this->isRevoked,
            'revokedAt' => $this->revokedAt,
            'revokedBy' => $this->revokedBy,
        ];
    }
}
