<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\RegistrationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webauthn\PublicKeyCredentialCreationOptions;

#[CoversClass(RegistrationOptions::class)]
final class RegistrationOptionsTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $options = $this->createMock(PublicKeyCredentialCreationOptions::class);
        $token = 'challenge-token-xyz';

        $dto = new RegistrationOptions(
            options: $options,
            challengeToken: $token,
        );

        self::assertSame($options, $dto->options);
        self::assertSame($token, $dto->challengeToken);
    }
}
