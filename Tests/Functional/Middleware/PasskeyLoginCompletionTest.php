<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\Middleware;

use Netresearch\NrPasskeysBe\Authentication\PasskeyLoginProofStore;
use Netresearch\NrPasskeysBe\Domain\Dto\VerifiedAssertion;
use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Middleware\PasskeyLoginCompletion;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Service\RateLimiterService;
use Netresearch\NrPasskeysBe\Service\WebAuthnService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use TYPO3\CMS\Backend\Middleware\BackendUserAuthenticator;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\SecurityAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webauthn\CredentialRecord;

use function array_keys;
use function array_search;
use function json_encode;
use function parse_url;
use function time;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * A passkey login through the core BackendUserAuthenticator middleware,
 * wrapped by PasskeyLoginCompletion as in the registered backend stack.
 *
 * The login request goes to the public login route, where the core swallows
 * the MFA requirement, and the next request decides: the browser presents the
 * session cookie, and the core authenticator either lets it through, asks for
 * the MFA challenge or sends it to the MFA setup.
 */
#[CoversClass(PasskeyLoginCompletion::class)]
#[CoversClass(PasskeyLoginProofStore::class)]
final class PasskeyLoginCompletionTest extends FunctionalTestCase
{
    private const USER_UID = 5;

    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    protected array $configurationToUseInTestInstance = [
        'BE'  => ['requireMfa' => 1],
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'nr_passkeys_be_nonce'     => ['backend' => TransientMemoryBackend::class],
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
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function middlewareWrapsTheCoreAuthenticationInTheBackendStack(): void
    {
        // The resolved stack is reversed: the dispatcher runs the last entry
        // first, so the outer middleware has the higher index.
        $order = array_keys($this
            ->get('backend.middlewares')
            ->getArrayCopy());

        self::assertGreaterThan(
            array_search('typo3/cms-backend/authentication', $order, true),
            array_search('netresearch/nr-passkeys-be/passkey-login-completion', $order, true),
        );
    }

    #[Test]
    public function verifiedPasskeyLoginOfATotpUserPassesMfaOnTheNextRequest(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $user = $this->login($this->assertionUident());

        self::assertTrue($this->storedSessionData($user, 'mfa'));
        self::assertSame(['passkey_authenticated' => true], $this->storedSessionData($user, 'tx_nrpasskeysbe'));
        self::assertSame(200, $this
            ->nextRequest($user)
            ->getStatusCode());
    }

    #[Test]
    public function loginResponseOfATotpUserLeadsToTheLoginTargetInsteadOfTheMfaSetup(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        // With requireMfa the core answers the login POST with a redirect to the
        // MFA setup; the middleware sends the user to the login target instead.
        $response = $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident()),
                $this->coreStack(new RedirectResponse('/typo3/main')),
            );

        self::assertSame($this->routePath('login'), parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
    }

    #[Test]
    public function loginResponseOfAUserWithoutMfaProviderStaysOnTheMfaSetup(): void
    {
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $response = $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident()),
                $this->coreStack(new RedirectResponse('/typo3/main')),
            );

        self::assertSame($this->routePath('setup_mfa'), parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
    }

    #[Test]
    public function verifiedTokenLoginOfATotpUserPassesMfaOnTheNextRequest(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $this
            ->get(CacheManager::class)
            ->getCache('nr_passkeys_be_nonce')
            ->set(
                'passkey_login_functional-token',
                json_encode(
                    ['uid' => self::USER_UID, 'expiresAt' => time() + 60, 'userVerified' => true],
                    JSON_THROW_ON_ERROR,
                ),
            );

        $user = $this->login(json_encode(['_type' => 'passkey_token', 'token' => 'functional-token'], JSON_THROW_ON_ERROR));

        self::assertTrue($this->storedSessionData($user, 'mfa'));
        self::assertSame(200, $this
            ->nextRequest($user)
            ->getStatusCode());
    }

    #[Test]
    public function withoutTheMiddlewareTheNextRequestIsChallenged(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $this
            ->authenticator()
            ->process(
                $this->loginRequest($this->assertionUident()),
                $this->finalHandler(new RedirectResponse('/typo3/main')),
            );
        $user = $this->currentUser();

        self::assertNull($this->storedSessionData($user, 'mfa'));
        $this->assertRedirectsTo('auth_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function passkeyUserWithoutMfaProviderIsStillSentToTheMfaSetup(): void
    {
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $user = $this->login($this->assertionUident());

        self::assertNull($this->storedSessionData($user, 'mfa'));
        self::assertSame(['passkey_authenticated' => true], $this->storedSessionData($user, 'tx_nrpasskeysbe'));
        $this->assertRedirectsTo('setup_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function presenceOnlyAssertionLeavesTheMfaChallengeInPlace(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: false);

        $user = $this->login($this->assertionUident());

        self::assertNull($this->storedSessionData($user, 'mfa'));
        self::assertSame(['passkey_authenticated' => true], $this->storedSessionData($user, 'tx_nrpasskeysbe'));
        $this->assertRedirectsTo('auth_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function disabledSkipMfaLeavesTheMfaChallengeInPlace(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: false, userVerified: true);

        $user = $this->login($this->assertionUident());

        self::assertNull($this->storedSessionData($user, 'mfa'));
        $this->assertRedirectsTo('auth_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function failedPasskeyVerificationWritesNothing(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true, verificationFails: true);

        $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident()),
                $this->coreStack(new RedirectResponse('/typo3/main')),
            );
        $user = $this->currentUser();

        self::assertTrue($user
            ->getSession()
            ->isAnonymous());
    }

    #[Test]
    public function lockedMfaProviderKeepsBlockingTheAccount(): void
    {
        $this->activateTotp(attempts: 3);
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $user = $this->login($this->assertionUident());

        self::assertNull($this->storedSessionData($user, 'mfa'));
        $this->assertRedirectsTo('auth_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function loginThroughAnAjaxRouteGetsNoMfaCredit(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident(), [], '/ajax/login'),
                $this->coreStack(new Response()),
            );
        $user = $this->currentUser();

        self::assertSame(self::USER_UID, $user
            ->getSession()
            ->getUserId());
        self::assertNull($this->storedSessionData($user, 'mfa'));
    }

    #[Test]
    public function verifiedPasskeyLoginOnAnExistingAnonymousSessionPassesMfa(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $manager   = UserSessionManager::create('BE');
        $anonymous = $manager->fixateAnonymousSession($manager->createAnonymousSession());

        $request = $this
            ->loginRequest($this->assertionUident())
            ->withCookieParams(['be_typo_user' => $anonymous->getJwt()]);
        $this
            ->middleware()
            ->process($request, $this->coreStack(new RedirectResponse('/typo3/main')));
        $user = $this->currentUser();

        self::assertSame(self::USER_UID, $user
            ->getSession()
            ->getUserId());
        self::assertTrue($this->storedSessionData($user, 'mfa'));
        self::assertSame(200, $this
            ->nextRequest($user)
            ->getStatusCode());
    }

    #[Test]
    public function authenticationServiceAndMiddlewareShareOneProofStore(): void
    {
        self::assertSame(
            $this->get(PasskeyLoginProofStore::class),
            GeneralUtility::makeInstance(PasskeyLoginProofStore::class),
        );
    }

    #[Test]
    public function redirectToTheMfaChallengeIsReplacedByTheLoginTarget(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $challenge = (new RedirectResponse($this->routePath('auth_mfa'), 303))->withAddedHeader(
            'Set-Cookie',
            'be_typo_user=functional; path=/typo3/',
        );

        $response = $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident(), ['redirect' => 'web_layout']),
                $this->coreThenRespond($challenge),
            );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['be_typo_user=functional; path=/typo3/'], $response->getHeader('Set-Cookie'));
        self::assertSame($this->routePath('login'), parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
        self::assertStringContainsString(
            'redirect=web_layout',
            (string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY),
        );
    }

    #[Test]
    public function redirectToTheMfaChallengeWithoutRedirectParameterTargetsTheLogin(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);

        $response = $this
            ->middleware()
            ->process(
                $this->loginRequest($this->assertionUident()),
                $this->coreThenRespond(new RedirectResponse($this->routePath('auth_mfa'))),
            );

        self::assertSame($this->routePath('login'), parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
    }

    #[Test]
    public function responsesWithoutRedirectTargetAreLeftUntouched(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $ok = new Response();

        self::assertSame(
            $ok,
            $this
                ->middleware()
                ->process($this->loginRequest($this->assertionUident()), $this->coreThenRespond($ok)),
        );
        self::assertTrue($this->storedSessionData($this->currentUser(), 'mfa'));

        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $redirectWithoutLocation = new Response('php://temp', 302);

        self::assertSame(
            $redirectWithoutLocation,
            $this
                ->middleware()
                ->process(
                    $this->loginRequest($this->assertionUident()),
                    $this->coreThenRespond($redirectWithoutLocation),
                ),
        );
    }

    #[Test]
    public function requestWithoutBackendUserIsLeftUntouched(): void
    {
        unset($GLOBALS['BE_USER']);
        $response = new Response();

        self::assertSame(
            $response,
            $this
                ->middleware()
                ->process($this->loginRequest($this->assertionUident()), $this->finalHandler($response)),
        );
    }

    #[Test]
    public function redirectToTheMfaSetupStaysForAUserWithoutMfaProvider(): void
    {
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $setup = new RedirectResponse($this->routePath('setup_mfa'));

        $response = $this
            ->middleware()
            ->process($this->loginRequest($this->assertionUident()), $this->coreThenRespond($setup));

        self::assertSame($this->routePath('setup_mfa'), $response->getHeaderLine('Location'));
    }

    #[Test]
    public function otherRedirectsAreLeftUntouched(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $other = new RedirectResponse('/typo3/module/web/layout');

        $response = $this
            ->middleware()
            ->process($this->loginRequest($this->assertionUident()), $this->coreThenRespond($other));

        self::assertSame('/typo3/module/web/layout', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function proofForAnotherUserIsNotApplied(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $store = $this->get(PasskeyLoginProofStore::class);
        $inner = $this->coreStack(new RedirectResponse('/typo3/main'));

        $handler = new class ($inner, $store) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequestHandlerInterface $inner,
                private readonly PasskeyLoginProofStore $store,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->inner->handle($request);
                $user     = $GLOBALS['BE_USER'];
                $this->store->take($user);
                $this->store->record($user, 99, true);

                return $response;
            }
        };

        $this
            ->middleware()
            ->process($this->loginRequest($this->assertionUident()), $handler);
        $user = $this->currentUser();

        self::assertNull($this->storedSessionData($user, 'mfa'));
        $this->assertRedirectsTo('auth_mfa', $this->nextRequest($user));
    }

    #[Test]
    public function proofIsDroppedWhenTheInnerStackThrows(): void
    {
        $this->activateTotp();
        $this->stubPasskeyServices(skipMfa: true, userVerified: true);
        $inner   = $this->coreStack(new RedirectResponse('/typo3/main'));
        $handler = new class ($inner) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $inner) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->inner->handle($request);

                throw new RuntimeException('inner failure', 1795500001);
            }
        };

        try {
            $this
                ->middleware()
                ->process($this->loginRequest($this->assertionUident()), $handler);
            self::fail('The exception of the inner stack must pass through.');
        } catch (RuntimeException $exception) {
            self::assertSame(1795500001, $exception->getCode());
        }

        self::assertNull($this
            ->get(PasskeyLoginProofStore::class)
            ->take($this->currentUser()));
    }

    // ── Helpers ──────────────────────────────────────────────
    /**
     * The login POST through PasskeyLoginCompletion and the core authenticator.
     */
    private function login(string $uident): BackendUserAuthentication
    {
        $this
            ->middleware()
            ->process(
                $this->loginRequest($uident),
                $this->coreStack(new RedirectResponse('/typo3/main')),
            );
        $user = $this->currentUser();
        self::assertSame(self::USER_UID, $user
            ->getSession()
            ->getUserId());

        return $user;
    }

    /**
     * The browser's next request to the backend: the session cookie, no login.
     */
    private function nextRequest(BackendUserAuthentication $previous): ResponseInterface
    {
        $request = (new ServerRequest('https://example.test/typo3/main', 'GET'))->withCookieParams(
            ['be_typo_user' => $previous
                ->getSession()
                ->getJwt()],
        );
        $request = $this->backendRequest($request, '/main');
        SecurityAspect::provideIn($this->get(Context::class))->setReceivedRequestToken(null);

        return $this
            ->authenticator()
            ->process($request, $this->finalHandler(new Response()));
    }

    private function middleware(): PasskeyLoginCompletion
    {
        return new PasskeyLoginCompletion(
            $this->get(PasskeyLoginProofStore::class),
            GeneralUtility::makeInstance(UriBuilder::class),
            $this->get(MfaProviderRegistry::class),
        );
    }

    private function authenticator(): MiddlewareInterface
    {
        return $this->get(BackendUserAuthenticator::class);
    }

    /**
     * The core authenticator with the given response as the rest of the stack.
     */
    private function coreStack(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($this->authenticator(), $this->finalHandler($response)) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $authenticator,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->authenticator->process($request, $this->next);
            }
        };
    }

    /**
     * Runs the core authenticator, then answers with the given response
     * instead of the core's own, to check how the middleware treats it.
     */
    private function coreThenRespond(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($this->coreStack(new Response()), $response) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequestHandlerInterface $core,
                private readonly ResponseInterface $response,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->core->handle($request);

                return $this->response;
            }
        };
    }

    private function finalHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * @param array<string, string> $query
     */
    private function loginRequest(string $uident, array $query = [], string $path = '/login'): ServerRequestInterface
    {
        $request = (new ServerRequest('https://example.test/typo3' . $path, 'POST'))
            ->withQueryParams($query)
            ->withParsedBody(
                ['login_status' => 'login', 'username' => 'adminuser', 'userident' => $uident],
            );
        $request = $this->backendRequest($request, $path);
        SecurityAspect::provideIn($this->get(Context::class))->setReceivedRequestToken(
            RequestToken::create('core/user-auth/be'),
        );

        return $request;
    }

    private function backendRequest(ServerRequestInterface $request, string $path): ServerRequestInterface
    {
        $request = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute(
                'route',
                $this
                    ->get(Router::class)
                    ->match($path),
            );
        $request                  = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function currentUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function assertRedirectsTo(string $route, ResponseInterface $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->routePath($route), parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
    }

    private function routePath(string $route): string
    {
        return (string) parse_url((string) GeneralUtility::makeInstance(UriBuilder::class)->buildUriFromRoute($route), PHP_URL_PATH);
    }

    private function storedSessionData(BackendUserAuthentication $user, string $key): mixed
    {
        $session = $user->getSession();
        self::assertFalse($session->isAnonymous());

        // Read back from the session backend, not from the in-memory object.
        return UserSessionManager::create('BE')
            ->createSessionFromStorage($session->getIdentifier())
            ->get($key);
    }

    private function assertionUident(): string
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

    private function activateTotp(int $attempts = 0): void
    {
        $this
            ->get(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->update(
                'be_users',
                [
                    'mfa' => json_encode(
                        ['totp' => ['active' => true, 'secret' => 'test-fixture-only', 'attempts' => $attempts]],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
                ['uid' => self::USER_UID],
            );
    }

    private function stubPasskeyServices(bool $skipMfa, bool $userVerified, bool $verificationFails = false): void
    {
        $typo3ExtConfig = $this->createMock(Typo3ExtensionConfiguration::class);
        $typo3ExtConfig
            ->method('get')
            ->with('nr_passkeys_be')
            ->willReturn(['skipMfaOnPasskeyAuth' => $skipMfa ? 1 : 0]);
        GeneralUtility::addInstance(
            ExtensionConfigurationService::class,
            new ExtensionConfigurationService($typo3ExtConfig),
        );

        $webAuthnService = $this->createMock(WebAuthnService::class);

        if ($verificationFails) {
            $webAuthnService
                ->method('verifyAssertionResponse')
                ->willThrowException(
                    new RuntimeException('invalid assertion', 1795500002),
                );
        } else {
            $webAuthnService
                ->method('verifyAssertionResponse')
                ->willReturn(
                    new VerifiedAssertion(
                        credential: new Credential(uid: 10, beUser: self::USER_UID, label: 'Functional Test Key'),
                        source: $this->createStub(CredentialRecord::class),
                        userVerified: $userVerified,
                    ),
                );
        }

        GeneralUtility::addInstance(WebAuthnService::class, $webAuthnService);
        GeneralUtility::addInstance(RateLimiterService::class, $this->createMock(RateLimiterService::class));
    }
}
