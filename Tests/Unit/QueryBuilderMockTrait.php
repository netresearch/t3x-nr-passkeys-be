<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Builds the QueryBuilder mock every single-row lookup in this extension needs.
 *
 * The fluent chain (select/from/where/expr/createNamedParameter/executeQuery)
 * is identical for each of those lookups, so it lives here once instead of per
 * test class. getRestrictions() is deliberately NOT stubbed: a caller that
 * expects the code under test to clear restrictions adds that stub itself, and
 * the ones that do not keep failing loudly if the code starts touching
 * restrictions.
 */
trait QueryBuilderMockTrait
{
    /**
     * A QueryBuilder whose query resolves to $row, or to no row when null.
     *
     * @param array<string, mixed>|null $row
     */
    private function createSingleRowQueryBuilder(int $uid, ?array $row): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder
            ->method('eq')
            ->willReturn('1=1');
        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn($row ?? false);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder
            ->method('select')
            ->willReturnSelf();
        $queryBuilder
            ->method('from')
            ->willReturnSelf();
        $queryBuilder
            ->method('where')
            ->willReturnSelf();
        $queryBuilder
            ->method('expr')
            ->willReturn($expressionBuilder);
        $queryBuilder
            ->method('createNamedParameter')
            ->willReturn((string) $uid);
        $queryBuilder
            ->method('executeQuery')
            ->willReturn($result);

        return $queryBuilder;
    }
}
