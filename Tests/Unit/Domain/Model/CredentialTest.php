<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Domain\Model;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Credential::class)]
final class CredentialTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $credential = new Credential();

        self::assertSame(0, $credential->getUid());
        self::assertSame(0, $credential->getPid());
        self::assertSame(0, $credential->getBeUser());
        self::assertSame('', $credential->getCredentialId());
        self::assertSame('', $credential->getPublicKeyCose());
        self::assertSame(0, $credential->getSignCount());
        self::assertSame('', $credential->getUserHandle());
        self::assertSame('', $credential->getAaguid());
        self::assertSame('[]', $credential->getTransports());
        self::assertSame('', $credential->getLabel());
        self::assertSame(0, $credential->getCreatedAt());
        self::assertSame(0, $credential->getLastUsedAt());
        self::assertSame(0, $credential->getRevokedAt());
        self::assertSame(0, $credential->getRevokedBy());
    }

    #[Test]
    public function constructorSetsProvidedValues(): void
    {
        $credential = new Credential(
            uid: 42,
            pid: 1,
            beUser: 7,
            credentialId: 'cred-id-abc',
            publicKeyCose: 'cose-key-data',
            signCount: 5,
            userHandle: 'user-handle-xyz',
            aaguid: '00000000-0000-0000-0000-000000000000',
            transports: '["usb","nfc"]',
            label: 'My YubiKey',
            createdAt: 1700000000,
            lastUsedAt: 1700001000,
            revokedAt: 1700002000,
            revokedBy: 99,
        );

        self::assertSame(42, $credential->getUid());
        self::assertSame(1, $credential->getPid());
        self::assertSame(7, $credential->getBeUser());
        self::assertSame('cred-id-abc', $credential->getCredentialId());
        self::assertSame('cose-key-data', $credential->getPublicKeyCose());
        self::assertSame(5, $credential->getSignCount());
        self::assertSame('user-handle-xyz', $credential->getUserHandle());
        self::assertSame('00000000-0000-0000-0000-000000000000', $credential->getAaguid());
        self::assertSame('["usb","nfc"]', $credential->getTransports());
        self::assertSame('My YubiKey', $credential->getLabel());
        self::assertSame(1700000000, $credential->getCreatedAt());
        self::assertSame(1700001000, $credential->getLastUsedAt());
        self::assertSame(1700002000, $credential->getRevokedAt());
        self::assertSame(99, $credential->getRevokedBy());
    }

    #[Test]
    public function setUidUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setUid(123);
        self::assertSame(123, $credential->getUid());
    }

    #[Test]
    public function setPidUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setPid(5);
        self::assertSame(5, $credential->getPid());
    }

    #[Test]
    public function setBeUserUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setBeUser(42);
        self::assertSame(42, $credential->getBeUser());
    }

    #[Test]
    public function setCredentialIdUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setCredentialId('new-cred-id');
        self::assertSame('new-cred-id', $credential->getCredentialId());
    }

    #[Test]
    public function setPublicKeyCoseUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setPublicKeyCose('new-cose-data');
        self::assertSame('new-cose-data', $credential->getPublicKeyCose());
    }

    #[Test]
    public function setSignCountUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setSignCount(10);
        self::assertSame(10, $credential->getSignCount());
    }

    #[Test]
    public function setUserHandleUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setUserHandle('handle-abc');
        self::assertSame('handle-abc', $credential->getUserHandle());
    }

    #[Test]
    public function setAaguidUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setAaguid('11111111-1111-1111-1111-111111111111');
        self::assertSame('11111111-1111-1111-1111-111111111111', $credential->getAaguid());
    }

    #[Test]
    public function setTransportsUpdatesRawJsonString(): void
    {
        $credential = new Credential();
        $credential->setTransports('["ble","internal"]');
        self::assertSame('["ble","internal"]', $credential->getTransports());
    }

    #[Test]
    public function setLabelUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setLabel('Office Key');
        self::assertSame('Office Key', $credential->getLabel());
    }

    #[Test]
    public function setCreatedAtUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setCreatedAt(1700050000);
        self::assertSame(1700050000, $credential->getCreatedAt());
    }

    #[Test]
    public function setLastUsedAtUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setLastUsedAt(1700060000);
        self::assertSame(1700060000, $credential->getLastUsedAt());
    }

    #[Test]
    public function setRevokedAtUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setRevokedAt(1700070000);
        self::assertSame(1700070000, $credential->getRevokedAt());
    }

    #[Test]
    public function setRevokedByUpdatesValue(): void
    {
        $credential = new Credential();
        $credential->setRevokedBy(88);
        self::assertSame(88, $credential->getRevokedBy());
    }

    #[Test]
    public function getTransportsArrayDecodesJsonToArray(): void
    {
        $credential = new Credential(transports: '["usb","nfc","ble"]');
        self::assertSame(['usb', 'nfc', 'ble'], $credential->getTransportsArray());
    }

    #[Test]
    public function getTransportsArrayReturnsEmptyArrayForEmptyJson(): void
    {
        $credential = new Credential(transports: '[]');
        self::assertSame([], $credential->getTransportsArray());
    }

    #[Test]
    public function getTransportsArrayReturnsEmptyArrayForInvalidJson(): void
    {
        $credential = new Credential(transports: 'not-valid-json');
        self::assertSame([], $credential->getTransportsArray());
    }

    #[Test]
    public function getTransportsArrayReturnsEmptyArrayForNonArrayJson(): void
    {
        $credential = new Credential(transports: '"just-a-string"');
        self::assertSame([], $credential->getTransportsArray());
    }

    #[Test]
    public function setTransportsArrayEncodesArrayToJson(): void
    {
        $credential = new Credential();
        $credential->setTransportsArray(['usb', 'internal']);
        self::assertSame('["usb","internal"]', $credential->getTransports());
    }

    #[Test]
    public function setTransportsArrayReindexesValues(): void
    {
        $credential = new Credential();
        $credential->setTransportsArray([2 => 'ble', 5 => 'nfc']);
        // array_values re-indexes keys, so the JSON should be a list
        self::assertSame('["ble","nfc"]', $credential->getTransports());
    }

    #[Test]
    public function setTransportsArrayWorksWithEmptyArray(): void
    {
        $credential = new Credential();
        $credential->setTransportsArray([]);
        self::assertSame('[]', $credential->getTransports());
    }

    #[Test]
    public function isRevokedReturnsFalseWhenRevokedAtIsZero(): void
    {
        $credential = new Credential(revokedAt: 0);
        self::assertFalse($credential->isRevoked());
    }

    #[Test]
    public function isRevokedReturnsTrueWhenRevokedAtIsPositive(): void
    {
        $credential = new Credential(revokedAt: 1700000000);
        self::assertTrue($credential->isRevoked());
    }

    #[Test]
    public function toArrayReturnsAllFieldsWithDatabaseColumnNames(): void
    {
        $credential = new Credential(
            uid: 1,
            pid: 2,
            beUser: 3,
            credentialId: 'cred-123',
            publicKeyCose: 'cose-data',
            signCount: 7,
            userHandle: 'handle-abc',
            aaguid: '00000000-0000-0000-0000-000000000001',
            transports: '["usb"]',
            label: 'Test Key',
            createdAt: 1700000000,
            lastUsedAt: 1700001000,
            revokedAt: 0,
            revokedBy: 0,
        );

        $expected = [
            'uid' => 1,
            'pid' => 2,
            'be_user' => 3,
            'credential_id' => 'cred-123',
            'public_key_cose' => 'cose-data',
            'sign_count' => 7,
            'user_handle' => 'handle-abc',
            'aaguid' => '00000000-0000-0000-0000-000000000001',
            'transports' => '["usb"]',
            'label' => 'Test Key',
            'created_at' => 1700000000,
            'last_used_at' => 1700001000,
            'revoked_at' => 0,
            'revoked_by' => 0,
        ];

        self::assertSame($expected, $credential->toArray());
    }

    #[Test]
    public function fromArrayCreatesCredentialFromDatabaseRow(): void
    {
        $data = [
            'uid' => 10,
            'pid' => 1,
            'be_user' => 5,
            'credential_id' => 'cred-from-db',
            'public_key_cose' => 'cose-from-db',
            'sign_count' => 3,
            'user_handle' => 'handle-from-db',
            'aaguid' => '22222222-2222-2222-2222-222222222222',
            'transports' => '["nfc"]',
            'label' => 'DB Key',
            'created_at' => 1700010000,
            'last_used_at' => 1700020000,
            'revoked_at' => 0,
            'revoked_by' => 0,
        ];

        $credential = Credential::fromArray($data);

        self::assertSame(10, $credential->getUid());
        self::assertSame(1, $credential->getPid());
        self::assertSame(5, $credential->getBeUser());
        self::assertSame('cred-from-db', $credential->getCredentialId());
        self::assertSame('cose-from-db', $credential->getPublicKeyCose());
        self::assertSame(3, $credential->getSignCount());
        self::assertSame('handle-from-db', $credential->getUserHandle());
        self::assertSame('22222222-2222-2222-2222-222222222222', $credential->getAaguid());
        self::assertSame('["nfc"]', $credential->getTransports());
        self::assertSame('DB Key', $credential->getLabel());
        self::assertSame(1700010000, $credential->getCreatedAt());
        self::assertSame(1700020000, $credential->getLastUsedAt());
        self::assertSame(0, $credential->getRevokedAt());
        self::assertSame(0, $credential->getRevokedBy());
    }

    #[Test]
    public function fromArrayHandlesMissingKeysWithDefaults(): void
    {
        $credential = Credential::fromArray([]);

        self::assertSame(0, $credential->getUid());
        self::assertSame(0, $credential->getPid());
        self::assertSame(0, $credential->getBeUser());
        self::assertSame('', $credential->getCredentialId());
        self::assertSame('', $credential->getPublicKeyCose());
        self::assertSame(0, $credential->getSignCount());
        self::assertSame('', $credential->getUserHandle());
        self::assertSame('', $credential->getAaguid());
        self::assertSame('[]', $credential->getTransports());
        self::assertSame('', $credential->getLabel());
        self::assertSame(0, $credential->getCreatedAt());
        self::assertSame(0, $credential->getLastUsedAt());
        self::assertSame(0, $credential->getRevokedAt());
        self::assertSame(0, $credential->getRevokedBy());
    }

    #[Test]
    public function fromArrayHandlesPartialData(): void
    {
        $credential = Credential::fromArray([
            'uid' => 99,
            'label' => 'Partial',
        ]);

        self::assertSame(99, $credential->getUid());
        self::assertSame('Partial', $credential->getLabel());
        self::assertSame(0, $credential->getBeUser());
        self::assertSame('', $credential->getCredentialId());
    }

    #[Test]
    public function toArrayRoundTripsWithFromArray(): void
    {
        $original = new Credential(
            uid: 5,
            pid: 1,
            beUser: 10,
            credentialId: 'round-trip-cred',
            publicKeyCose: 'round-trip-cose',
            signCount: 42,
            userHandle: 'round-trip-handle',
            aaguid: '33333333-3333-3333-3333-333333333333',
            transports: '["usb","ble"]',
            label: 'Round Trip Key',
            createdAt: 1700050000,
            lastUsedAt: 1700060000,
            revokedAt: 1700070000,
            revokedBy: 77,
        );

        $restored = Credential::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function toPublicArrayReturnsOnlyPublicFields(): void
    {
        $credential = new Credential(
            uid: 42,
            pid: 1,
            beUser: 7,
            credentialId: 'secret-cred-id',
            publicKeyCose: 'secret-cose-data',
            signCount: 5,
            userHandle: 'secret-handle',
            aaguid: '00000000-0000-0000-0000-000000000000',
            transports: '["usb"]',
            label: 'My Key',
            createdAt: 1700000000,
            lastUsedAt: 1700001000,
            revokedAt: 0,
            revokedBy: 0,
        );

        $public = $credential->toPublicArray();

        self::assertSame(42, $public['uid']);
        self::assertSame('My Key', $public['label']);
        self::assertSame(1700000000, $public['createdAt']);
        self::assertSame(1700001000, $public['lastUsedAt']);
        self::assertFalse($public['isRevoked']);

        // Ensure sensitive fields are NOT exposed
        self::assertArrayNotHasKey('credentialId', $public);
        self::assertArrayNotHasKey('credential_id', $public);
        self::assertArrayNotHasKey('publicKeyCose', $public);
        self::assertArrayNotHasKey('public_key_cose', $public);
        self::assertArrayNotHasKey('userHandle', $public);
        self::assertArrayNotHasKey('user_handle', $public);
        self::assertArrayNotHasKey('beUser', $public);
        self::assertArrayNotHasKey('be_user', $public);
        self::assertArrayNotHasKey('signCount', $public);
        self::assertArrayNotHasKey('sign_count', $public);
        self::assertArrayNotHasKey('aaguid', $public);
        self::assertArrayNotHasKey('transports', $public);
    }

    #[Test]
    public function toPublicArrayReflectsRevokedState(): void
    {
        $credential = new Credential(
            uid: 1,
            label: 'Revoked Key',
            createdAt: 1700000000,
            lastUsedAt: 1700001000,
            revokedAt: 1700002000,
        );

        $public = $credential->toPublicArray();

        self::assertTrue($public['isRevoked']);
    }

    #[Test]
    public function toPublicArrayContainsExactlyFiveKeys(): void
    {
        $credential = new Credential(uid: 1, label: 'Test');
        $public = $credential->toPublicArray();

        self::assertCount(5, $public);
        self::assertArrayHasKey('uid', $public);
        self::assertArrayHasKey('label', $public);
        self::assertArrayHasKey('createdAt', $public);
        self::assertArrayHasKey('lastUsedAt', $public);
        self::assertArrayHasKey('isRevoked', $public);
    }
}
