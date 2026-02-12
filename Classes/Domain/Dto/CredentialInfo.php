<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use JsonSerializable;

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
    ) {}

    /**
     * @return array{uid: int, label: string, createdAt: int, lastUsedAt: int, isRevoked: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'isRevoked' => $this->isRevoked,
        ];
    }
}
