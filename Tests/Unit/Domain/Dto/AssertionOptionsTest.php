<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Dto;

use Netresearch\NrPasskeysBe\Domain\Dto\AssertionOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webauthn\PublicKeyCredentialRequestOptions;

#[CoversClass(AssertionOptions::class)]
final class AssertionOptionsTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $options = $this->createMock(PublicKeyCredentialRequestOptions::class);
        $token = 'challenge-token-abc';

        $dto = new AssertionOptions(
            options: $options,
            challengeToken: $token,
        );

        self::assertSame($options, $dto->options);
        self::assertSame($token, $dto->challengeToken);
    }
}
