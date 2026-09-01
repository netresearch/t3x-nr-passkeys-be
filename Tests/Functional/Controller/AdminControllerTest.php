<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Controller;

use Netresearch\NrPasskeysBe\Controller\AdminController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the admin API controller.
 *
 * Tests all 6 AJAX endpoints with real database operations.
 */
#[CoversClass(AdminController::class)]
final class AdminControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce' => ['backend' => NullBackend::class],
                    'nr_passkeys_be_ratelimit' => ['backend' => NullBackend::class],
                ],
            ],
        ],
    ];

    private AdminController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Repository/Fixtures/tx_nrpasskeysbe_credential.csv');
        $this->subject = $this->get(AdminController::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────
    private function setUpAdminUser(int $uid = 5): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'username' => 'adminuser', 'realName' => 'Admin User'];
        $backendUser
            ->method('isAdmin')
            ->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function setUpNonAdminUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'username' => 'testuser1', 'realName' => 'Test User'];
        $backendUser
            ->method('isAdmin')
            ->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function createGetRequest(string $uri, array $queryParams = []): ServerRequest
    {
        return (new ServerRequest($uri, 'GET'))->withQueryParams($queryParams);
    }

    private function createPostRequest(string $uri, array $body): ServerRequest
    {
        return (new ServerRequest($uri, 'POST'))->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $content = (string) $response->getBody();
        $data = \json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        return $data;
    }

    // ── listAction ──────────────────────────────────────────
    #[Test]
    public function listActionReturnsCredentialsForUser(): void
    {
        $this->setUpAdminUser();
        $request = $this->createGetRequest('/passkeys/admin/list', ['beUserUid' => '1']);
        $response = $this->subject->listAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $data['beUserUid']);
        self::assertIsArray($data['credentials']);

        // User 1 has 2 active + 1 revoked (findAllByBeUser includes revoked, not deleted)
        self::assertSame(3, $data['count']);
    }

    #[Test]
    public function listActionReturnsEmptyForUserWithNoCredentials(): void
    {
        $this->setUpAdminUser();
        $request = $this->createGetRequest('/passkeys/admin/list', ['beUserUid' => '99']);
        $response = $this->subject->listAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $data['count']);
        self::assertSame([], $data['credentials']);
    }

    #[Test]
    public function listActionRejects400WhenMissingBeUserUid(): void
    {
        $this->setUpAdminUser();
        $request = $this->createGetRequest('/passkeys/admin/list');
        $response = $this->subject->listAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function listActionRejects403WhenNotAdmin(): void
    {
        $this->setUpNonAdminUser();
        $request = $this->createGetRequest('/passkeys/admin/list', ['beUserUid' => '1']);
        $response = $this->subject->listAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function listActionRejects403WhenNoUser(): void
    {
        // No BE_USER set
        $request = $this->createGetRequest('/passkeys/admin/list', ['beUserUid' => '1']);
        $response = $this->subject->listAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    // ── removeAction ────────────────────────────────────────
    #[Test]
    public function removeActionRevokesCredential(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/remove', ['beUserUid' => 1, 'credentialUid' => 1]);
        $response = $this->subject->removeAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $data['status']);

        // Verify the credential is now revoked in the database
        $queryBuilder = $this
            ->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrpasskeysbe_credential');
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $row = $queryBuilder
            ->select('revoked_at', 'revoked_by')
            ->from('tx_nrpasskeysbe_credential')
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('uid', 1),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertGreaterThan(0, (int) $row['revoked_at']);
        self::assertSame(5, (int) $row['revoked_by']);
    }

    #[Test]
    public function removeActionRejects404ForWrongUser(): void
    {
        $this->setUpAdminUser();

        // Credential 5 belongs to user 2, not user 1
        $request = $this->createPostRequest('/passkeys/admin/remove', ['beUserUid' => 1, 'credentialUid' => 5]);
        $response = $this->subject->removeAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function removeActionRejects400WhenMissingFields(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/remove', ['beUserUid' => 1]);
        $response = $this->subject->removeAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function removeActionRejects403WhenNotAdmin(): void
    {
        $this->setUpNonAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/remove', ['beUserUid' => 1, 'credentialUid' => 1]);
        $response = $this->subject->removeAction($request);
        self::assertSame(403, $response->getStatusCode());
    }

    // ── unlockAction ────────────────────────────────────────
    #[Test]
    public function unlockActionResetsLockout(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/unlock', ['beUserUid' => 1, 'username' => 'testuser1']);
        $response = $this->subject->unlockAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $data['status']);
    }

    #[Test]
    public function unlockActionRejects404OnUsernameMismatch(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/unlock', ['beUserUid' => 1, 'username' => 'wrong-username']);
        $response = $this->subject->unlockAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unlockActionRejects400WhenMissingFields(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/unlock', ['beUserUid' => 1]);
        $response = $this->subject->unlockAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    // ── revokeAllAction ─────────────────────────────────────
    #[Test]
    public function revokeAllActionRevokesActiveCredentials(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/revoke-all', ['beUserUid' => 1]);
        $response = $this->subject->revokeAllAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $data['status']);

        // User 1 has 2 active credentials (uid 1, 2) — uid 3 already revoked
        self::assertSame(2, $data['revokedCount']);

        // Verify all active credentials are now revoked
        $queryBuilder = $this
            ->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_nrpasskeysbe_credential');
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $activeCount = $queryBuilder
            ->count('*')
            ->from('tx_nrpasskeysbe_credential')
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('be_user', 1),
                $queryBuilder
                    ->expr()
                    ->eq('revoked_at', 0),
                $queryBuilder
                    ->expr()
                    ->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, (int) $activeCount);
    }

    #[Test]
    public function revokeAllActionRejects400WhenMissingFields(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/revoke-all', []);
        $response = $this->subject->revokeAllAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    // ── updateEnforcementAction ─────────────────────────────
    #[Test]
    public function updateEnforcementActionChangesGroupLevel(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/update-enforcement', ['groupUid' => 3, 'enforcement' => 'enforced']);
        $response = $this->subject->updateEnforcementAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $data['status']);
        self::assertSame('enforced', $data['enforcement']);

        // Verify the group enforcement was updated in database
        $queryBuilder = $this
            ->get(ConnectionPool::class)
            ->getQueryBuilderForTable('be_groups');
        $row = $queryBuilder
            ->select('passkey_enforcement')
            ->from('be_groups')
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('uid', 3),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame('enforced', $row['passkey_enforcement']);
    }

    #[Test]
    public function updateEnforcementActionRejects400ForInvalidLevel(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest(
            '/passkeys/admin/update-enforcement',
            ['groupUid' => 1, 'enforcement' => 'invalid-level'],
        );
        $response = $this->subject->updateEnforcementAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function updateEnforcementActionRejects404ForNonexistentGroup(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest(
            '/passkeys/admin/update-enforcement',
            ['groupUid' => 9999, 'enforcement' => 'encourage'],
        );
        $response = $this->subject->updateEnforcementAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateEnforcementActionRejects400WhenMissingFields(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/update-enforcement', ['groupUid' => 1]);
        $response = $this->subject->updateEnforcementAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    // ── sendReminderAction ──────────────────────────────────
    #[Test]
    public function sendReminderActionSetsNudgeTimestamp(): void
    {
        $this->setUpAdminUser();
        $beforeTime = \time();
        $request = $this->createPostRequest('/passkeys/admin/send-reminder', ['beUserUid' => 1]);
        $response = $this->subject->sendReminderAction($request);
        $data = $this->decodeJsonResponse($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $data['status']);
        self::assertArrayHasKey('nudgeUntil', $data);

        // Nudge should be 14 days in the future
        $expectedMin = $beforeTime + 14 * 86400;
        self::assertGreaterThanOrEqual($expectedMin, $data['nudgeUntil']);

        // Verify the nudge timestamp was set in database
        $queryBuilder = $this
            ->get(ConnectionPool::class)
            ->getQueryBuilderForTable('be_users');
        $row = $queryBuilder
            ->select('passkey_nudge_until')
            ->from('be_users')
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('uid', 1),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame($data['nudgeUntil'], (int) $row['passkey_nudge_until']);
    }

    #[Test]
    public function sendReminderActionRejects404ForNonexistentUser(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/send-reminder', ['beUserUid' => 9999]);
        $response = $this->subject->sendReminderAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function sendReminderActionRejects400WhenMissingFields(): void
    {
        $this->setUpAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/send-reminder', []);
        $response = $this->subject->sendReminderAction($request);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function sendReminderActionRejects403WhenNotAdmin(): void
    {
        $this->setUpNonAdminUser();
        $request = $this->createPostRequest('/passkeys/admin/send-reminder', ['beUserUid' => 1]);
        $response = $this->subject->sendReminderAction($request);
        self::assertSame(403, $response->getStatusCode());
    }
}
