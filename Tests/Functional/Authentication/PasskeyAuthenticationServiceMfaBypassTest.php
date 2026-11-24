<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Authentication;

use Netresearch\NrPasskeysBe\Authentication\PasskeyAuthenticationService;
use Netresearch\NrPasskeysBe\Authentication\PasskeyLoginProofStore;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaRequiredException;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webauthn\CredentialRecord;

use function json_encode;

/**
 * Functional tests for the skipMfaOnPasskeyAuth feature.
 *
 * Uses a real BackendUserAuthentication instance with real database-backed
 * session storage (via setUpBackendUser()) so that setAndSaveSessionData()
 * and getSessionData() go through the production code path rather than a
 * mocked in-memory stub. This guards against session-storage regressions
 * that unit tests cannot catch.
 */
#[CoversClass(PasskeyAuthenticationService::class)]
final class PasskeyAuthenticationServiceMfaBypassTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce'     => ['backend' => NullBackend::class],
                    'nr_passkeys_be_ratelimit' => ['backend' => NullBackend::class],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * Core level only: BackendUserAuthentication::start() still challenges a
     * fresh session. The PasskeyLoginCompletion middleware completes the login
     * afterwards, see Tests/Functional/Middleware/PasskeyLoginCompletionTest.
     */
    #[Test]
    public function freshLoginThroughCoreChallengesTheConfiguredMfaProvider(): void
    {
        $this->stubExtensionConfigServiceWithSkipMfaFlag(true);
        $this->stubWebAuthnServiceWithVerifiedAssertion();
        $this->stubRateLimiterService();
        $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->update(
                'be_users',
                ['mfa' => json_encode(['totp' => ['active' => true, 'secret' => 'test-fixture-only']], JSON_THROW_ON_ERROR)],
                ['uid' => 5],
            );
        $request = (new ServerRequest('https://example.test/typo3/login', 'POST'))->withParsedBody(
            ['login_status' => 'login', 'username' => 'adminuser', 'userident' => $this->buildPasskeyPayload()],
        );
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        // The request-token middleware normally supplies this login-scoped token.
        SecurityAspect::provideIn($this->get(Context::class))->setReceivedRequestToken(
            RequestToken::create('core/user-auth/be'),
        );
        $backendUser        = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $backendUser->start($request);
            self::fail('Expected the configured MFA provider to challenge the fresh session.');
        } catch (MfaRequiredException $required) {
            self::assertSame('totp', $required
                ->getProvider()
                ->getIdentifier());
        }

        self::assertSame(5, $backendUser
            ->getSession()
            ->getUserId());
        self::assertNull($backendUser->getSessionData('mfa'));
    }

    #[Test]
    public function successfulPasskeyAuthRecordsTheMfaCreditWhenSkipMfaOnPasskeyAuthEnabled(): void
    {
        $backendUser = $this->setUpBackendUser(5);
        $this->stubExtensionConfigServiceWithSkipMfaFlag(true);
        $this->stubWebAuthnServiceWithVerifiedAssertion();
        $this->stubRateLimiterService();
        $service        = new PasskeyAuthenticationService();
        $service->pObj  = $backendUser;
        $service->login = ['uname' => 'adminuser', 'uident' => $this->buildPasskeyPayload()];

        $result = $service->authUser($backendUser->user ?? []);
        self::assertSame(200, $result);

        // The service never writes 'mfa' itself: whether the passkey stands in
        // for the second factor depends on the user's active MFA providers,
        // which the PasskeyLoginCompletion middleware checks.
        self::assertNull($backendUser->getSessionData('mfa'));
        self::assertSame(['passkey_authenticated' => true], $backendUser->getSessionData('tx_nrpasskeysbe'));
        self::assertSame(
            ['uid' => 5, 'creditMfa' => true],
            $this
                ->get(PasskeyLoginProofStore::class)
                ->take($backendUser),
        );
    }

    #[Test]
    public function successfulPasskeyAuthDoesNotSetMfaSessionKeyWhenSkipMfaOnPasskeyAuthDisabled(): void
    {
        $backendUser = $this->setUpBackendUser(5);
        $this->stubExtensionConfigServiceWithSkipMfaFlag(false);
        $this->stubWebAuthnServiceWithVerifiedAssertion();
        $this->stubRateLimiterService();
        $service        = new PasskeyAuthenticationService();
        $service->pObj  = $backendUser;
        $service->login = ['uname' => 'adminuser', 'uident' => $this->buildPasskeyPayload()];

        $result = $service->authUser($backendUser->user ?? []);
        self::assertSame(200, $result);
        self::assertNull(
            $backendUser->getSessionData('mfa'),
            "The 'mfa' session key must not be written when the admin has " . 'opted out of the bypass via skipMfaOnPasskeyAuth=false. ' . "TYPO3's native MFA flow must remain untouched in that mode.",
        );
    }

    #[Test]
    public function assertionWithoutUserVerificationLeavesCoreMfaPending(): void
    {
        $backendUser = $this->setUpBackendUser(5);
        $this->stubExtensionConfigServiceWithSkipMfaFlag(true);
        $this->stubWebAuthnServiceWithVerifiedAssertion(false);
        $this->stubRateLimiterService();
        $service        = new PasskeyAuthenticationService();
        $service->pObj  = $backendUser;
        $service->login = ['uname' => 'adminuser', 'uident' => $this->buildPasskeyPayload()];

        self::assertSame(200, $service->authUser($backendUser->user ?? []));
        self::assertNull($backendUser->getSessionData('mfa'));
    }

    #[Test]
    public function passwordLoginDoesNotSetMfaSessionKeyEvenWhenSkipMfaOnPasskeyAuthEnabled(): void
    {
        $backendUser = $this->setUpBackendUser(5);
        $this->stubExtensionConfigServiceWithSkipMfaFlag(true);
        $service        = new PasskeyAuthenticationService();
        $service->pObj  = $backendUser;
        $service->login = ['uname' => 'adminuser', 'uident' => 'regularPassword123'];

        $result = $service->authUser($backendUser->user ?? []);

        // No passkey payload → auth passes through to the next service.
        self::assertSame(100, $result);
        self::assertNull($this
            ->get(PasskeyLoginProofStore::class)
            ->take($backendUser));
        self::assertNull(
            $backendUser->getSessionData('mfa'),
            'Password logins must never trigger the MFA bypass. The bypass is only valid when the primary authentication factor is a passkey.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────
    /**
     * Wire a fresh ExtensionConfigurationService into the makeInstance FIFO
     * queue so that PasskeyAuthenticationService resolves our stubbed value
     * instead of the DI-cached instance.
     */
    private function stubExtensionConfigServiceWithSkipMfaFlag(bool $skipMfa): void
    {
        $typo3ExtConfig = $this->createMock(Typo3ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->with('nr_passkeys_be')
            ->willReturn(['skipMfaOnPasskeyAuth' => $skipMfa ? 1 : 0]);
        $configService = new ExtensionConfigurationService($typo3ExtConfig);
        GeneralUtility::addInstance(ExtensionConfigurationService::class, $configService);
    }

    private function stubWebAuthnServiceWithVerifiedAssertion(bool $userVerified = true): void
    {
        $credential = new Credential(uid: 10, beUser: 5, label: 'Functional Test Key');
        $verified   = new VerifiedAssertion(
            credential: $credential,
            source: $this->createStub(CredentialRecord::class),
            userVerified: $userVerified,
        );
        $webAuthnService = $this->createMock(WebAuthnService::class);
        $webAuthnService
            ->method('verifyAssertionResponse')
            ->willReturn($verified);
        GeneralUtility::addInstance(WebAuthnService::class, $webAuthnService);
    }

    private function stubRateLimiterService(): void
    {
        $rateLimiterService = $this->createMock(RateLimiterService::class);

        // All methods are stubbed (void-ish), no explicit setup needed.
        GeneralUtility::addInstance(RateLimiterService::class, $rateLimiterService);
    }

    private function buildPasskeyPayload(): string
    {
        return json_encode(
            [
                '_type'          => 'passkey',
                'assertion'      => ['functional' => 'test-assertion'],
                'challengeToken' => 'functional-test-token',
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
