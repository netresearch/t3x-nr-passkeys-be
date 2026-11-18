<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DatabaseRateLimitStore
{
    private const TABLE = 'tx_nrpasskeysbe_rate_limit';

    public function __construct(private ConnectionPool $pool) {}

    /** Check and charge one attempt on the database primary. */
    public function increment(string $key, string $group, int $ttl, int $limit): bool
    {
        $db = $this->pool->getConnectionForTable(self::TABLE);

        for ($attempt = 0;; ++$attempt) {
            try {
                $charged = $db->transactional(
                    function () use ($db, $key, $group, $ttl, $limit): ?bool {
                        $q = $db->createQueryBuilder();
                        $q
                            ->update(self::TABLE)
                            ->set('attempts', $db->quoteIdentifier('attempts'), false)
                            ->where(
                                $q
                                    ->expr()
                                    ->eq('key_hash', $q->createNamedParameter($key)),
                            )
                            ->executeStatement();
                        $criteria = ['key_hash' => $key];
                        $row      = $db
                            ->select(['attempts', 'expires_at'], self::TABLE, $criteria)
                            ->fetchAssociative();
                        $now   = time();
                        $count = is_array($row) && is_numeric($row['expires_at'] ?? null) && (int) $row['expires_at'] > $now && is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0;

                        if ($count >= $limit) {
                            return false;
                        }

                        $values = ['attempts' => $count + 1, 'expires_at' => $now + $ttl, 'group_hash' => $group];

                        if ($row === false) {
                            $db->insert(self::TABLE, $criteria + $values);
                        } else {
                            // A concurrent insert can become visible after the initial UPDATE.
                            // Charge only the exact row state that supplied this decision.
                            $expected = $criteria + ['attempts' => $row['attempts'], 'expires_at' => $row['expires_at']];

                            if ($db->update(self::TABLE, $values, $expected) !== 1) {
                                return null;
                            }
                        }

                        return true;
                    },
                );

                if ($charged !== null) {
                    return $charged;
                }

                if ($attempt >= 2) {
                    throw new RuntimeException('Rate-limit counter changed during three consecutive attempts.', 1700000012);
                }
            } catch (UniqueConstraintViolationException|RetryableException $error) {
                if ($attempt >= 2) {
                    throw $error;
                }
            }
        }
    }

    public function count(string $key): int
    {
        $q      = $this->pool->getQueryBuilderForTable(self::TABLE);
        $result = $q
            ->select('attempts')
            ->from(self::TABLE)
            ->where(
                $q
                    ->expr()
                    ->eq('key_hash', $q->createNamedParameter($key)),
                $q
                    ->expr()
                    ->gt('expires_at', $q->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function forget(string $key): void
    {
        $this->pool
            ->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['key_hash' => $key]);
    }

    public function forgetGroup(string $group): void
    {
        $this->pool
            ->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['group_hash' => $group]);
    }

    public function prune(): void
    {
        $q = $this->pool->getQueryBuilderForTable(self::TABLE);
        $q
            ->delete(self::TABLE)
            ->where(
                $q
                    ->expr()
                    ->lte('expires_at', $q->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->executeStatement();
    }
}
