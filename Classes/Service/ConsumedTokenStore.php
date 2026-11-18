<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Service;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** The primary key arbitrates single-use claims across application nodes. */
final readonly class ConsumedTokenStore
{
    private const TABLE = 'tx_nrpasskeysbe_consumed_token';

    public function __construct(private ConnectionPool $connectionPool, private LoggerInterface $logger) {}

    public function claim(string $key, int $expiresAt): bool
    {
        $now = time();

        if ($expiresAt <= $now) {
            return false;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        if (random_int(1, 64) === 1) {
            try {
                $this->pruneExpired();
            } catch (DbalException $error) {
                $this->logger->warning('Passkey token cleanup failed.', ['exception' => $error::class]);
            }
        }

        try {
            return $connection->insert(self::TABLE, ['token_hash' => hash('sha256', $key), 'expires_at' => $expiresAt]) === 1 && time() < $expiresAt;
        } catch (UniqueConstraintViolationException) {
            $this->logger->notice('Rejected a previously consumed passkey token.');

            return false;
        }
    }

    /** Retain recently expired claims for an hour to tolerate clock differences between nodes. */
    public function pruneExpired(): void
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $query
            ->delete(self::TABLE)
            ->where(
                $query
                    ->expr()
                    ->lte('expires_at', $query->createNamedParameter(time() - 3600, ParameterType::INTEGER)),
            )
            ->executeStatement();
    }
}
