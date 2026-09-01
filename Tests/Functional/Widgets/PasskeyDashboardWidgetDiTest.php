<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Widgets;

use Netresearch\NrPasskeysBe\Service\BackendPasskeyAdoptionStatsProvider;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyAdoptionChartDataProvider;
use Netresearch\NrPasskeysBe\Widgets\DataProvider\PasskeyCredentialsCountDataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DI smoke test for the unified dashboard widget wiring.
 *
 * Verifies that the DI tag nr_passkeys_be.adoption_stats_provider collects
 * the backend provider exactly once and that both revised data providers
 * receive the tagged_iterator (no double-tagging: the credentials total is
 * the backend count, not twice it).
 */
final class PasskeyDashboardWidgetDiTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup', 'dashboard'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    // No cache override needed: this DI smoke test resolves the stats
    // providers only and never invokes the nonce/rate-limit caches, so the
    // file-backend defaults registered in ext_localconf.php are fine.
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/tx_nrpasskeysbe_credential.csv');
    }

    #[Test]
    public function backendProviderIsRegisteredAndReturnsTheBackendSegment(): void
    {
        $provider = $this->get(BackendPasskeyAdoptionStatsProvider::class);
        self::assertInstanceOf(BackendPasskeyAdoptionStatsProvider::class, $provider);
        $stats = $provider->getAudienceStats();
        self::assertSame('backend', $stats->audienceKey);
        // be_users.csv: 5 active users; credential fixture: 2 distinct users
        // with active credentials, 3 active credentials of active users.
        self::assertSame(5, $stats->totalActiveUsers);
        self::assertSame(2, $stats->usersWithPasskeys);
        self::assertSame(3, $stats->activeCredentials);
    }

    #[Test]
    public function credentialsProviderReceivesTheTaggedProviderExactlyOnce(): void
    {
        $provider = $this->get(PasskeyCredentialsCountDataProvider::class);
        self::assertInstanceOf(PasskeyCredentialsCountDataProvider::class, $provider);
        // Exactly the backend segment (3), never double-counted (would be 6
        // if the provider were tagged twice via autoconfigure + explicit tag).
        self::assertSame(3, $provider->getNumber());
    }

    #[Test]
    public function adoptionChartProviderRendersASingleBackendRing(): void
    {
        $provider = $this->get(PasskeyAdoptionChartDataProvider::class);
        self::assertInstanceOf(PasskeyAdoptionChartDataProvider::class, $provider);
        $chartData = $provider->getChartData();
        // One provider in the iterator => one dataset (backend green/orange).
        self::assertCount(1, $chartData['datasets']);
        self::assertSame(['#4c7e3a', '#ff8700'], $chartData['datasets'][0]['backgroundColor']);
        self::assertSame([2, 3], $chartData['datasets'][0]['data']);
    }
}
