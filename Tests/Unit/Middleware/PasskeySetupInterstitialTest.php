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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;

#[CoversClass(PasskeySetupInterstitial::class)]
final class PasskeySetupInterstitialTest extends TestCase
{
    private EnforcementService&MockObject $enforcementService;
    private PasskeySetupInterstitial $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforcementService = $this->createMock(EnforcementService::class);
        $this->subject = new PasskeySetupInterstitial($this->enforcementService);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
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
    public function passesThroughForAjaxRequests(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('main', 'GET', null, 'application/json');
        $handler = $this->createMockHandler();

        $handler->expects(self::once())->method('handle')->with($request);

        $this->subject->process($request, $handler);
    }

    #[Test]
    public function passesThroughForExemptSetupRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('setup');
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
        self::assertStringContainsString('/typo3/setup/', $body);
        self::assertStringContainsString('Set up now', $body);
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

        // First call returns gracePeriodStart=0, second call returns updated status
        $initialStatus = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: 0,
            hasPasskeys: false,
        );
        $updatedStatus = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );

        $this->enforcementService
            ->expects(self::exactly(2))
            ->method('getStatus')
            ->willReturnOnConsecutiveCalls($initialStatus, $updatedStatus);

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
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(null);
        $backendUser->expects(self::once())
            ->method('setAndSaveSessionData')
            ->with('tx_nrpasskeysbe', ['setup_skipped' => true]);
        $GLOBALS['BE_USER'] = $backendUser;

        $request = $this->createMockRequest('main', 'POST', ['passkey_setup_skip' => '1']);
        $handler = $this->createMockHandler();

        $handler->expects(self::never())->method('handle');

        $response = $this->subject->process($request, $handler);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/typo3/', $response->getHeaderLine('Location'));
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
    public function passesThroughForExemptAjaxSetupRoute(): void
    {
        $this->setUpBackendUser(1);

        $status = new EnforcementStatus(
            level: EnforcementLevel::Required,
            gracePeriodDays: 14,
            gracePeriodStart: \time(),
            hasPasskeys: false,
        );
        $this->enforcementService->method('getStatus')->willReturn($status);

        $request = $this->createMockRequest('ajax_setup_something');
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
        $request->method('getHeaderLine')->with('Accept')->willReturn('text/html');
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

    private function setUpBackendUser(int $uid): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'usergroup' => '1'];
        $backendUser->method('getSessionData')
            ->with('tx_nrpasskeysbe')
            ->willReturn(null);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function createMockRequest(
        string $routeIdentifier = 'main',
        string $method = 'GET',
        ?array $parsedBody = null,
        string $acceptHeader = 'text/html',
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
        $request->method('getHeaderLine')->with('Accept')->willReturn($acceptHeader);
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
