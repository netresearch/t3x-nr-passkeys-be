<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

/**
 * Value object representing the currently authenticated backend user.
 *
 * Pure data container with no framework dependencies.
 * Factory logic lives in the controller layer.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $uid,
        public string $username,
        public string $realName,
        public bool $isAdmin,
    ) {}
}
