<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Data access layer for WebAuthn passkey credentials (tx_nrpasskeysbe_credential).
 */
final readonly class CredentialRepository
{
    private const TABLE = 'tx_nrpasskeysbe_credential';

    public function __construct(private ConnectionPool $connectionPool, private LoggerInterface $logger) {}

    public function findByCredentialId(string $credentialId): ?Credential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq(
                        'credential_id',
                        // credential_id is varbinary; bind as BINARY so the value matches
                        // regardless of DB engine. On SQLite a string-bound param is a TEXT
                        // storage class and never equals the BLOB the value is stored as.
                        $queryBuilder->createNamedParameter($credentialId, ParameterType::BINARY),
                    ),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
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
                $queryBuilder
                    ->expr()
                    ->eq(
                        'be_user',
                        $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                    ),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
                $queryBuilder
                    ->expr()
                    ->eq('revoked_at', 0),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(Credential::fromArray(...), $rows);
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

        // Bind the binary columns by their schema type so the stored storage class
        // matches what findByCredentialId() queries with (cross-DB; see SQLite note there).
        $connection->insert(
            self::TABLE,
            $data,
            [
                'credential_id' => ParameterType::BINARY,
                'public_key_cose' => ParameterType::LARGE_OBJECT,
                'user_handle' => ParameterType::BINARY,
            ],
        );
        $uid = (int) $connection->lastInsertId();
        $this->logger->info(
            'Passkey credential created',
            ['credentialUid' => $uid, 'beUser' => $credential->getBeUser()],
        );

        return $uid;
    }

    public function updateLastUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(self::TABLE, ['last_used_at' => $now, 'tstamp' => $now], ['uid' => $uid]);
    }

    public function updateSignCount(int $uid, int $newCount): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['sign_count' => $newCount, 'tstamp' => \time()], ['uid' => $uid]);
        $this->logger->debug('Credential sign count updated', ['credentialUid' => $uid, 'newSignCount' => $newCount]);
    }

    public function updateLabel(int $uid, string $label): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['label' => $label, 'tstamp' => \time()], ['uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['deleted' => 1, 'tstamp' => \time()], ['uid' => $uid]);
        $this->logger->info('Passkey credential deleted (soft delete)', ['credentialUid' => $uid]);
    }

    public function revoke(int $uid, int $adminUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = \time();
        $connection->update(
            self::TABLE,
            ['revoked_at' => $now, 'revoked_by' => $adminUid, 'tstamp' => $now],
            ['uid' => $uid],
        );
        $this->logger->warning(
            'Passkey credential revoked',
            ['credentialUid' => $uid, 'revokedByAdminUid' => $adminUid],
        );
    }

    public function countByBeUser(int $beUserUid): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq(
                        'be_user',
                        $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                    ),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
                $queryBuilder
                    ->expr()
                    ->eq('revoked_at', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return \is_numeric($result) ? (int) $result : 0;
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
                $queryBuilder
                    ->expr()
                    ->eq(
                        'be_user',
                        $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                    ),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return \array_map(Credential::fromArray(...), $rows);
    }

    public function findByUidAndBeUser(int $uid, int $beUserUid): ?Credential
    {
        $queryBuilder = $this->getQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder
                    ->expr()
                    ->eq(
                        'be_user',
                        $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER),
                    ),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return Credential::fromArray($row);
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
