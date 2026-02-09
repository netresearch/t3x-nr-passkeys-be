<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Repository;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(CredentialRepository::class)]
final class CredentialRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'setup',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-passkeys-be',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                    'nr_passkeys_be_ratelimit' => [
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class,
                    ],
                ],
            ],
        ],
    ];

    private CredentialRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->repository = $this->get(CredentialRepository::class);
    }

    #[Test]
    public function saveReturnsAutoIncrementedUid(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'test-credential-id-1',
            publicKeyCose: 'test-public-key-cose-data',
            signCount: 0,
            userHandle: 'test-user-handle',
            aaguid: '00000000-0000-0000-0000-000000000000',
            transports: '["usb","nfc"]',
            label: 'Test Credential',
        );

        $uid = $this->repository->save($credential);

        self::assertGreaterThan(0, $uid);
        self::assertSame(1, $uid, 'First inserted credential should have UID 1');
    }

    #[Test]
    public function saveStoresAllFieldsCorrectly(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 5,
            credentialId: 'cred-complete',
            publicKeyCose: 'complete-cose-data',
            signCount: 10,
            userHandle: 'complete-handle',
            aaguid: '11111111-1111-1111-1111-111111111111',
            transports: '["internal","hybrid"]',
            label: 'Complete Credential',
        );

        $uid = $this->repository->save($credential);

        $found = $this->repository->findByCredentialId('cred-complete');
        self::assertNotNull($found);
        self::assertSame($uid, $found->getUid());
        self::assertSame(5, $found->getBeUser());
        self::assertSame('cred-complete', $found->getCredentialId());
        self::assertSame('complete-cose-data', $found->getPublicKeyCose());
        self::assertSame(10, $found->getSignCount());
        self::assertSame('complete-handle', $found->getUserHandle());
        self::assertSame('11111111-1111-1111-1111-111111111111', $found->getAaguid());
        self::assertSame('["internal","hybrid"]', $found->getTransports());
        self::assertSame('Complete Credential', $found->getLabel());
    }

    #[Test]
    public function findByCredentialIdReturnsCredentialWhenFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credential = $this->repository->findByCredentialId('credential-id-active-1');

        self::assertInstanceOf(Credential::class, $credential);
        self::assertSame(1, $credential->getUid());
        self::assertSame('credential-id-active-1', $credential->getCredentialId());
        self::assertSame(1, $credential->getBeUser());
    }

    #[Test]
    public function findByCredentialIdReturnsNullWhenNotFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credential = $this->repository->findByCredentialId('non-existent-credential');

        self::assertNull($credential);
    }

    #[Test]
    public function findByCredentialIdReturnsNullForDeletedCredential(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credential = $this->repository->findByCredentialId('credential-id-deleted');

        self::assertNull($credential);
    }

    #[Test]
    public function findByBeUserReturnsOnlyActiveCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credentials = $this->repository->findByBeUser(1);

        self::assertCount(2, $credentials);
        self::assertSame('credential-id-active-1', $credentials[0]->getCredentialId());
        self::assertSame('credential-id-active-2', $credentials[1]->getCredentialId());
    }

    #[Test]
    public function findByBeUserExcludesDeletedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credentials = $this->repository->findByBeUser(1);

        foreach ($credentials as $credential) {
            self::assertNotSame('credential-id-deleted', $credential->getCredentialId());
        }
    }

    #[Test]
    public function findByBeUserExcludesRevokedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credentials = $this->repository->findByBeUser(1);

        foreach ($credentials as $credential) {
            self::assertSame(0, $credential->getRevokedAt());
        }
    }

    #[Test]
    public function findByBeUserReturnsEmptyArrayWhenNoCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credentials = $this->repository->findByBeUser(999);

        self::assertSame([], $credentials);
    }

    #[Test]
    public function findByBeUserOrdersByCreatedAtDescending(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $credentials = $this->repository->findByBeUser(1);

        self::assertGreaterThan(
            $credentials[1]->getCreatedAt(),
            $credentials[0]->getCreatedAt(),
            'Credentials should be ordered by created_at DESC',
        );
    }

    #[Test]
    public function countByBeUserReturnsCorrectCount(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $count = $this->repository->countByBeUser(1);

        self::assertSame(2, $count);
    }

    #[Test]
    public function countByBeUserExcludesDeletedAndRevokedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $count = $this->repository->countByBeUser(1);

        self::assertSame(2, $count, 'Count should exclude deleted and revoked credentials');
    }

    #[Test]
    public function countByBeUserReturnsZeroWhenNoActiveCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $count = $this->repository->countByBeUser(999);

        self::assertSame(0, $count);
    }

    #[Test]
    public function deleteSoftDeletesCredential(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-to-delete',
            publicKeyCose: 'cose-data',
            label: 'To Delete',
        );

        $uid = $this->repository->save($credential);
        $this->repository->delete($uid);

        $found = $this->repository->findByCredentialId('cred-to-delete');
        self::assertNull($found, 'Deleted credential should not be found');
    }

    #[Test]
    public function deletePreservesRecordInDatabase(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-soft-delete',
            publicKeyCose: 'cose-data',
            label: 'Soft Delete',
        );

        $uid = $this->repository->save($credential);
        $this->repository->delete($uid);

        // Query directly to verify record still exists with deleted=1
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrpasskeysbe_credential');
        $result = $connection->select(['*'], 'tx_nrpasskeysbe_credential', ['uid' => $uid]);
        $row = $result->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(1, (int) $row['deleted']);
    }

    #[Test]
    public function revokeSetsRevokedAtAndRevokedBy(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-to-revoke',
            publicKeyCose: 'cose-data',
            label: 'To Revoke',
        );

        $uid = $this->repository->save($credential);
        $adminUid = 42;
        $beforeRevoke = \time();

        $this->repository->revoke($uid, $adminUid);

        $afterRevoke = \time();
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrpasskeysbe_credential');
        $result = $connection->select(['*'], 'tx_nrpasskeysbe_credential', ['uid' => $uid]);
        $row = $result->fetchAssociative();

        self::assertNotFalse($row);
        $revokedAt = (int) $row['revoked_at'];
        self::assertGreaterThanOrEqual($beforeRevoke, $revokedAt);
        self::assertLessThanOrEqual($afterRevoke, $revokedAt);
        self::assertSame($adminUid, (int) $row['revoked_by']);
    }

    #[Test]
    public function revokeExcludesCredentialFromFindByBeUser(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 10,
            credentialId: 'cred-revoke-exclude',
            publicKeyCose: 'cose-data',
            label: 'Revoke Exclude',
        );

        $uid = $this->repository->save($credential);
        $countBefore = $this->repository->countByBeUser(10);
        self::assertSame(1, $countBefore);

        $this->repository->revoke($uid, 1);

        $countAfter = $this->repository->countByBeUser(10);
        self::assertSame(0, $countAfter);

        $credentials = $this->repository->findByBeUser(10);
        self::assertEmpty($credentials);
    }

    #[Test]
    public function updateLastUsedUpdatesTimestamp(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-update-last-used',
            publicKeyCose: 'cose-data',
            label: 'Update Last Used',
        );

        $uid = $this->repository->save($credential);
        $beforeUpdate = \time();

        $this->repository->updateLastUsed($uid);

        $afterUpdate = \time();
        $found = $this->repository->findByCredentialId('cred-update-last-used');

        self::assertNotNull($found);
        self::assertGreaterThanOrEqual($beforeUpdate, $found->getLastUsedAt());
        self::assertLessThanOrEqual($afterUpdate, $found->getLastUsedAt());
    }

    #[Test]
    public function updateSignCountUpdatesCounter(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-update-sign-count',
            publicKeyCose: 'cose-data',
            signCount: 5,
            label: 'Update Sign Count',
        );

        $uid = $this->repository->save($credential);
        $newCount = 42;

        $this->repository->updateSignCount($uid, $newCount);

        $found = $this->repository->findByCredentialId('cred-update-sign-count');

        self::assertNotNull($found);
        self::assertSame($newCount, $found->getSignCount());
    }

    #[Test]
    public function updateLabelUpdatesLabelValue(): void
    {
        $credential = new Credential(
            pid: 0,
            beUser: 1,
            credentialId: 'cred-update-label',
            publicKeyCose: 'cose-data',
            label: 'Original Label',
        );

        $uid = $this->repository->save($credential);
        $newLabel = 'Updated Label';

        $this->repository->updateLabel($uid, $newLabel);

        $found = $this->repository->findByCredentialId('cred-update-label');

        self::assertNotNull($found);
        self::assertSame($newLabel, $found->getLabel());
    }

    #[Test]
    public function findAllByBeUserReturnsAllIncludingRevoked(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $all = $this->repository->findAllByBeUser(1);

        self::assertCount(3, $all, 'Should return active + revoked, but not deleted');

        $hasRevoked = false;
        foreach ($all as $credential) {
            if ($credential->isRevoked()) {
                $hasRevoked = true;
                break;
            }
        }

        self::assertTrue($hasRevoked, 'Should include revoked credentials');
    }

    #[Test]
    public function findAllByBeUserExcludesDeletedCredentials(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $all = $this->repository->findAllByBeUser(1);

        foreach ($all as $credential) {
            self::assertNotSame('credential-id-deleted', $credential->getCredentialId());
        }
    }

    #[Test]
    public function findAllByBeUserOrdersByCreatedAtDescending(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tx_nrpasskeysbe_credential.csv');

        $all = $this->repository->findAllByBeUser(1);

        for ($i = 0; $i < \count($all) - 1; $i++) {
            self::assertGreaterThanOrEqual(
                $all[$i + 1]->getCreatedAt(),
                $all[$i]->getCreatedAt(),
                'Credentials should be ordered by created_at DESC',
            );
        }
    }
}
