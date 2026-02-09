<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use TYPO3\CMS\Core\Database\ConnectionPool;

class CredentialRepository
{
    private const TABLE = 'tx_nrpasskeysbe_credential';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByCredentialId(string $credentialId): ?Credential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'credential_id',
                    $queryBuilder->createNamedParameter($credentialId),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Credential::fromArray($row);
    }

    /**
     * @return list<Credential>
     */
    public function findByBeUser(int $beUserUid): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(
            static fn(array $row): Credential => Credential::fromArray($row),
            $rows,
        );
    }

    public function save(Credential $credential): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();

        $data = $credential->toArray();
        unset($data['uid']);
        $data['tstamp'] = $now;
        $data['crdate'] = $now;
        $data['created_at'] = $now;

        $connection->insert(self::TABLE, $data);

        return (int) $connection->lastInsertId();
    }

    public function updateLastUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(
            self::TABLE,
            [
                'last_used_at' => $now,
                'tstamp' => $now,
            ],
            ['uid' => $uid],
        );
    }

    public function updateSignCount(int $uid, int $newCount): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'sign_count' => $newCount,
                'tstamp' => \time(),
            ],
            ['uid' => $uid],
        );
    }

    public function updateLabel(int $uid, string $label): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'label' => $label,
                'tstamp' => \time(),
            ],
            ['uid' => $uid],
        );
    }

    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'deleted' => 1,
                'tstamp' => \time(),
            ],
            ['uid' => $uid],
        );
    }

    public function revoke(int $uid, int $adminUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(
            self::TABLE,
            [
                'revoked_at' => $now,
                'revoked_by' => $adminUid,
                'tstamp' => $now,
            ],
            ['uid' => $uid],
        );
    }

    public function countByBeUser(int $beUserUid): int
    {
        $queryBuilder = $this->getQueryBuilder();

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<Credential>
     */
    public function findAllByBeUser(int $beUserUid): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(
            static fn(array $row): Credential => Credential::fromArray($row),
            $rows,
        );
    }

    public function findByUidAndBeUser(int $uid, int $beUserUid): ?Credential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Credential::fromArray($row);
    }

    private function getQueryBuilder(): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
