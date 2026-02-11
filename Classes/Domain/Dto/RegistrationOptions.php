<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Value object wrapping WebAuthn registration options and challenge token.
 */
final readonly class RegistrationOptions
{
    public function __construct(
        public PublicKeyCredentialCreationOptions $options,
        public string $challengeToken,
    ) {}
}
