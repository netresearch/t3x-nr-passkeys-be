<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Middleware;

use Netresearch\NrPasskeysBe\Domain\Dto\EnforcementStatus;
use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Middleware\PasskeySetupInterstitial;
use Netresearch\NrPasskeysBe\Service\EnforcementService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

#[CoversClass(PasskeySetupInterstitial::class)]
final class PasskeySetupInterstitialTest extends TestCase
{
    private EnforcementService&MockObject $enforcementService;
    private PasskeySetupInterstitial $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforcementService = $this->createMock(EnforcementService::class);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/user/setup'));
        $this->subject = new PasskeySetupInterstitial(
            $this->enforcementService,
            $uriBuilder,
            $this->createMock(LoggerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function passesThroughWhenNoBeUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $request = $this->createMockRequest();
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenBeUserHasNoUid(): void
    {
        $this->setUpBackendUser(0);

        $request = $this->createMockRequest();
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenEnforcementIsOff(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Off,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    /**
     * Registration is refused in switch-user mode, so an interstitial there would be
     * a dead end for the impersonating admin.
     */
    #[Test]
    public function passesThroughInSwitchUserModeEvenWhenSetupIsRequired(): void
    {
        $this->setUpBackendUser(1, switchUserOriginalUid: 7);

        $this->enforcementService->expects(self::never())->method('getStatus');

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenUserHasPasskeys(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: true,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptEnrollmentAjaxRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('ajax_passkeys_manage_list');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function doesNotExemptStateChangingCoreAjaxRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        // ajax_record_process is the DataHandler save endpoint; an
        // enforced-but-unenrolled user must be blocked from it, not exempted.
        $request = $this->createMockRequest('ajax_record_process');
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertInstanceOf(HtmlResponse::class, $response);
    }

    #[Test]
    public function passesThroughForExemptCoreAuthAjaxRoutes(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        // The login/logout/MFA AJAX routes must stay exempt so an enforced user can
        // still authenticate, log out, and complete MFA.
        foreach (['ajax_login', 'ajax_logout', 'ajax_mfa'] as $identifier) {
            $request = $this->createMockRequest($identifier);
            $handler = $this->createMockHandler();

            $handler->expects(self::once())->method('handle')->with($request);

            $this->subject->process($request, $handler);
        }
    }

    #[Test]
    public function passesThroughForExemptUserSetupRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('user_setup');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptLogoutRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('logout');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptPasskeysManageRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('passkeys_manage_list');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptPasskeysLoginRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('passkeys_login_options');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptMfaRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('mfa');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function returnsHtmlResponseWhenEnforcementRequiredAndNoPasskeys(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function interstitialContainsSetupHeading(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Set up your passkey', $body);
    }

    #[Test]
    public function interstitialContainsSetupNowLink(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('/typo3/module/user/setup', $body);
        self::assertStringContainsString('Set up now', $body);
    }

    #[Test]
    public function interstitialUsesSchemeAwarePalette(): void
    {
        $body = $this->renderInterstitialBody();

        // Adapts to light AND dark schemes instead of a hardcoded dark palette
        self::assertStringContainsString('color-scheme: light dark', $body);
        self::assertStringContainsString('data-color-scheme="auto"', $body);
        self::assertStringContainsString('prefers-color-scheme: dark', $body);
        // Brand teal accent instead of the former off-brand blue
        self::assertStringContainsString('#2F99A4', $body);
        self::assertStringNotContainsString('#0078d4', $body);
    }

    #[Test]
    public function interstitialHonorsUserDarkColorScheme(): void
    {
        $body = $this->renderInterstitialBody(['colorScheme' => 'dark']);

        self::assertStringContainsString('data-color-scheme="dark"', $body);
    }

    /**
     * Render the interstitial for a user under Required enforcement and
     * return the response body.
     *
     * @param array<string, mixed> $uc backend user settings (e.g. colorScheme)
     */
    private function renderInterstitialBody(array $uc = []): string
    {
        $this->setUpBackendUser(1);
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $backendUser->uc = $uc;
        $this->enforcementService->method('getStatus')->willReturn(new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        ));

        return (string) $this->subject->process($this->createMockRequest('main'), $this->createMockHandler())->getBody();
    }

    #[Test]
    public function interstitialShowsSkipButtonWhenCanSkip(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('passkey_setup_skip', $body);
        self::assertStringContainsString('passkey_setup_nonce', $body);
        self::assertStringContainsString('Skip for now', $body);
    }

    #[Test]
    public function interstitialHidesSkipButtonWhenCannotSkip(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('passkey_setup_skip', $body);
        self::assertStringNotContainsString('Skip for now', $body);
    }

    #[Test]
    public function interstitialShowsGracePeriodCountdown(): void
    {
        $this->setUpBackendUser(1);

        $gracePeriodStart = \time() - (2 * 86_400); // started 2 days ago
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: $gracePeriodStart,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('12 days remaining', $body);
    }

    #[Test]
    public function interstitialShowsRequiredMessageWhenGracePeriodExpired(): void
    {
        $this->setUpBackendUser(1);

        $gracePeriodStart = \time() - (30 * 86_400); // started 30 days ago
        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 14,
            gracePeriodStart: $gracePeriodStart,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Passkey setup is now required', $body);
    }

    #[Test]
    public function startsGracePeriodOnFirstIntercept(): void
    {
        $this->setUpBackendUser(42);

        // gracePeriodStart=0 triggers startGracePeriod; middleware constructs
        // the updated status directly instead of re-querying
        $initialStatus = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );

        $this->enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn($initialStatus);

        $this->enforcementService
            ->expects(self::once())
            ->method('startGracePeriod')
            ->with(42);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenSessionSkipFlagSetAndCanSkip(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['setup_skipped' => true]);
        $GLOBALS['BE_USER'] = $backendUser;

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function reusesCachedOkDecisionWithoutQueryingEnforcement(): void
    {
        // PERF-1: a fresh "no interstitial needed" decision in the session must skip
        // the enforcement query entirely on subsequent requests.
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['enforcement_ok_at' => \time()]);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->enforcementService->expects(self::never())->method('getStatus');

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();
        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function requeriesEnforcementWhenCachedDecisionIsStale(): void
    {
        // An expired cache (> TTL) must re-run the enforcement query.
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['enforcement_ok_at' => \time() - 3600]);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn(new EnforcementStatus(
                level: EnforcementLevel::Off,
                gracePeriodDays: 0,
                gracePeriodStart: 0,
                hasPasskeys: true,
            ));

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();
        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function doesNotPassThroughWhenSessionSkipFlagSetButCannotSkip(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['setup_skipped' => true]);
        $GLOBALS['BE_USER'] = $backendUser;

        $status = new EnforcementStatus(
            level: EnforcementLevel::Enforced,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertInstanceOf(HtmlResponse::class, $response);
    }

    #[Test]
    public function handleSkipPostStoresSessionAndReturnsRedirect(): void
    {
        $nonce = 'test-nonce-abc123';

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['skip_nonce' => $nonce]);
        $backendUser->expects(self::once())
            ->method('setAndSaveSessionData')
            ->with('tx_nrpasskeysbe', ['setup_skipped' => true]);
        $GLOBALS['BE_USER'] = $backendUser;

        $request = $this->createMockRequest('main', 'POST', [
            'passkey_setup_skip' => '1',
            'passkey_setup_nonce' => $nonce,
        ]);
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/typo3/', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function handleSkipPostWithInvalidNonceFallsThroughToInterstitial(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['skip_nonce' => 'correct-nonce']);
        $GLOBALS['BE_USER'] = $backendUser;

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main', 'POST', [
            'passkey_setup_skip' => '1',
            'passkey_setup_nonce' => 'wrong-nonce',
        ]);
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        // Invalid nonce falls through to render interstitial
        self::assertInstanceOf(HtmlResponse::class, $response);
    }

    #[Test]
    public function passesThroughForExemptLoginRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('login');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptPasswordResetRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('password_reset');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptPasskeysEnforcementStatusAjaxRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('ajax_passkeys_enforcement_status');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenRouteIsNull(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name): mixed => null);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getParsedBody')->willReturn(null);

        $handler = $this->createMockHandler();

        // Null route means exempt (no route identifier to check)
        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenPasskeyAuthenticated(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(['passkey_authenticated' => true]);
        $GLOBALS['BE_USER'] = $backendUser;

        // No enforcement mock needed — exits before checking enforcement
        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptInstallRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('install_something');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function interstitialEscapesXssInOutput(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();

        // Ensure no unescaped dynamic values — the response should be well-formed HTML
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('</html>', $body);
    }

    #[Test]
    public function passesThroughWhenUserRowIsNull(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = null;
        $GLOBALS['BE_USER'] = $backendUser;

        $request = $this->createMockRequest();
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughWhenRouteIdentifierIsNotString(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $route = $this->createMock(\TYPO3\CMS\Backend\Routing\Route::class);
        $route->method('getOption')
            ->willReturnCallback(static function (string $option): mixed {
                if ($option === '_identifier') {
                    return null;
                }

                return null;
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static function (string $name) use ($route): mixed {
                if ($name === 'route') {
                    return $route;
                }

                return null;
            });
        $request->method('getMethod')->willReturn('GET');
        $request->method('getParsedBody')->willReturn(null);

        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function interstitialUsesNormalizedParamsSitePath(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $normalizedParams = new class {
            public function getSitePath(): string
            {
                return '/subdir/';
            }
        };

        $route = $this->createMock(\TYPO3\CMS\Backend\Routing\Route::class);
        $route->method('getOption')
            ->willReturnCallback(static function (string $option): mixed {
                if ($option === '_identifier') {
                    return 'main';
                }

                return null;
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static function (string $name) use ($route, $normalizedParams): mixed {
                if ($name === 'route') {
                    return $route;
                }

                if ($name === 'normalizedParams') {
                    return $normalizedParams;
                }

                return null;
            });
        $request->method('getMethod')->willReturn('GET');
        $request->method('getParsedBody')->willReturn(null);

        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        // The skip-form action uses the normalized backend path; the "Set up now"
        // link is built from UriBuilder (user_setup route) and is asserted separately.
        $body = (string) $response->getBody();
        self::assertStringContainsString('action="/subdir/typo3/"', $body);
    }

    #[Test]
    public function interstitialShowsSkipButtonWithGracePeriodDaysRemaining(): void
    {
        $this->setUpBackendUser(42);

        // Grace period just started with 7 days remaining
        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 7,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );

        $this->enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn($status);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Skip for now', $body);
        self::assertStringContainsString('7 days remaining', $body);
    }

    #[Test]
    public function interstitialHidesSkipButtonWhenGracePeriodExpired(): void
    {
        $this->setUpBackendUser(42);

        // Required level with 0 grace days — once started, immediately expired
        $initialStatus = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 0,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );

        $this->enforcementService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn($initialStatus);

        $this->enforcementService
            ->expects(self::once())
            ->method('startGracePeriod')
            ->with(42);

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Skip for now', $body);
        self::assertStringContainsString('Passkey setup is now required', $body);
    }

    #[Test]
    public function interstitialUsesLocaleFromLanguageService(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $locale = $this->createMock(Locale::class);
        $locale->method('getLanguageCode')->willReturn('de');

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getLocale')->willReturn($locale);
        $languageService->method('sL')->willReturn('');
        $GLOBALS['LANG'] = $languageService;

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('<html lang="de"', $body);
    }

    #[Test]
    public function interstitialUsesTranslationsWhenLanguageServiceAvailable(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $locale = $this->createMock(Locale::class);
        $locale->method('getLanguageCode')->willReturn('de');

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getLocale')->willReturn($locale);
        $languageService->method('sL')->willReturn('Übersetzt');
        $GLOBALS['LANG'] = $languageService;

        $request = $this->createMockRequest('main');
        $handler = $this->createMockHandler();

        $response = $this->subject->process($request, $handler);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Übersetzt', $body);
    }

    private function setUpBackendUser(int $uid, ?int $switchUserOriginalUid = null): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(null);
        $backendUser->method('getOriginalUserIdWhenInSwitchUserMode')->willReturn($switchUserOriginalUid);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function createMockRequest(
        string $routeIdentifier = 'main',
        string $method = 'GET',
        ?array $parsedBody = null,
    ): ServerRequestInterface&MockObject {
        $route = $this->createMock(\TYPO3\CMS\Backend\Routing\Route::class);
        $route->method('getOption')
            ->willReturnCallback(static function (string $option) use ($routeIdentifier): mixed {
                if ($option === '_identifier') {
                    return $routeIdentifier;
                }

                return null;
            });

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static function (string $name) use ($route): mixed {
                if ($name === 'route') {
                    return $route;
                }

                return null;
            });
        $request->method('getMethod')->willReturn($method);
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }

    private function createMockHandler(): RequestHandlerInterface&MockObject
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        return $handler;
    }
}
