<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Webauthn\PublicKeyCredentialSource;

/**
 * Value object wrapping a verified WebAuthn assertion result.
 */
final readonly class VerifiedAssertion
{
    public function __construct(
        public Credential $credential,
        public PublicKeyCredentialSource $source,
    ) {}
}
