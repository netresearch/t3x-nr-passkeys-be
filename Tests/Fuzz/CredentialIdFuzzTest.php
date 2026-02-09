<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Fuzz;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CredentialIdFuzzTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fuzzedCredentialDataProvider(): iterable
    {
        yield 'empty array' => [[]];
        yield 'null values' => [['uid' => null, 'be_user' => null, 'credential_id' => null]];
        yield 'negative uid' => [['uid' => -1, 'be_user' => -1]];
        yield 'max int uid' => [['uid' => PHP_INT_MAX, 'be_user' => PHP_INT_MAX]];
        yield 'string uid' => [['uid' => 'not_a_number']];
        yield 'float uid' => [['uid' => 3.14]];
        yield 'binary credential_id' => [['credential_id' => \random_bytes(255)]];
        yield 'empty credential_id' => [['credential_id' => '']];
        yield 'huge credential_id' => [['credential_id' => \str_repeat('X', 10000)]];
        yield 'null byte credential_id' => [['credential_id' => "\x00\x00\x00"]];
        yield 'unicode label' => [['label' => '🔑 Mëîn Schlüssel 日本語']];
        yield 'long label' => [['label' => \str_repeat('A', 500)]];
        yield 'html label' => [['label' => '<b>Bold</b><script>alert(1)</script>']];
        yield 'malformed transports json' => [['transports' => 'not json']];
        yield 'transports with objects' => [['transports' => '{"key": "value"}']];
        yield 'transports with nested arrays' => [['transports' => '[[["deep"]]]']];
        yield 'empty transports' => [['transports' => '']];
        yield 'null transports' => [['transports' => null]];
        yield 'negative timestamps' => [['created_at' => -1, 'last_used_at' => -1]];
        yield 'future timestamps' => [['created_at' => PHP_INT_MAX, 'last_used_at' => PHP_INT_MAX]];
        yield 'invalid aaguid' => [['aaguid' => 'not-a-uuid']];
        yield 'empty aaguid' => [['aaguid' => '']];
        yield 'oversized public_key_cose' => [['public_key_cose' => \random_bytes(65536)]];
        yield 'all zeros' => [['uid' => 0, 'be_user' => 0, 'sign_count' => 0, 'created_at' => 0]];
    }

    #[Test]
    #[DataProvider('fuzzedCredentialDataProvider')]
    public function fromArrayHandlesFuzzedInput(array $data): void
    {
        // Should not throw - just create a Credential with coerced values
        $credential = Credential::fromArray($data);

        $this->assertInstanceOf(Credential::class, $credential);
        $this->assertIsInt($credential->getUid());
        $this->assertIsInt($credential->getBeUser());
        $this->assertIsString($credential->getCredentialId());
        $this->assertIsString($credential->getLabel());
    }

    #[Test]
    #[DataProvider('fuzzedCredentialDataProvider')]
    public function toArrayAndBackRoundTrips(array $data): void
    {
        $credential = Credential::fromArray($data);
        $array = $credential->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('uid', $array);
        $this->assertArrayHasKey('be_user', $array);
        $this->assertArrayHasKey('credential_id', $array);

        // Round-trip
        $credential2 = Credential::fromArray($array);
        $this->assertSame($credential->getUid(), $credential2->getUid());
        $this->assertSame($credential->getCredentialId(), $credential2->getCredentialId());
    }

    #[Test]
    public function transportsArrayHandlesMalformedJson(): void
    {
        $testCases = [
            'not json',
            '{broken',
            '42',
            'true',
            'null',
            '',
            '{"object": true}',
        ];

        foreach ($testCases as $transport) {
            $credential = new Credential(transports: $transport);
            $result = $credential->getTransportsArray();

            // Should return an array (possibly empty) without throwing
            $this->assertIsArray($result);
        }
    }

    #[Test]
    public function toPublicArrayNeverLeaksSensitiveData(): void
    {
        $credential = new Credential(
            uid: 1,
            beUser: 42,
            credentialId: \random_bytes(32),
            publicKeyCose: \random_bytes(256),
            userHandle: \random_bytes(32),
            aaguid: 'test-aaguid',
            transports: '["usb"]',
            label: 'Test Key',
            createdAt: \time(),
            lastUsedAt: \time(),
        );

        $public = $credential->toPublicArray();

        // Must NOT contain sensitive fields
        $this->assertArrayNotHasKey('credentialId', $public);
        $this->assertArrayNotHasKey('credential_id', $public);
        $this->assertArrayNotHasKey('publicKeyCose', $public);
        $this->assertArrayNotHasKey('public_key_cose', $public);
        $this->assertArrayNotHasKey('userHandle', $public);
        $this->assertArrayNotHasKey('user_handle', $public);
        $this->assertArrayNotHasKey('beUser', $public);
        $this->assertArrayNotHasKey('be_user', $public);
        $this->assertArrayNotHasKey('aaguid', $public);

        // Must contain only safe fields
        $this->assertArrayHasKey('uid', $public);
        $this->assertArrayHasKey('label', $public);
        $this->assertArrayHasKey('createdAt', $public);
        $this->assertArrayHasKey('lastUsedAt', $public);
        $this->assertArrayHasKey('isRevoked', $public);
    }
}
