<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\EventListener\InjectPasskeyLoginFields;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionUnionType;
use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;

#[CoversClass(InjectPasskeyLoginFields::class)]
final class InjectPasskeyLoginFieldsTest extends TestCase
{
    private ExtensionConfigurationService&MockObject $configService;

    private PageRenderer&MockObject $pageRenderer;

    private UriBuilder&MockObject $uriBuilder;

    private InjectPasskeyLoginFields $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturnCallback(static function (string $routeName): Uri {
                $routeMap = [
                    'passkeys_login_options' => '/typo3/passkeys/login/options',
                ];

                return new Uri($routeMap[$routeName] ?? '/typo3/unknown');
            });

        $this->subject = new InjectPasskeyLoginFields(
            $this->configService,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    private function createEvent(): ModifyPageLayoutOnLoginProviderSelectionEvent
    {
        $constructor = new ReflectionMethod(
            ModifyPageLayoutOnLoginProviderSelectionEvent::class,
            '__construct',
        );

        $paramCount = $constructor->getNumberOfParameters();

        // TYPO3 v14: (ViewInterface, ServerRequestInterface) — 2 params
        if ($paramCount === 2) {
            return new ModifyPageLayoutOnLoginProviderSelectionEvent(
                $this->createMock(ViewInterface::class),
                $this->createMock(ServerRequestInterface::class),
            );
        }

        // TYPO3 v12/v13: (LoginController, view, PageRenderer, ServerRequestInterface) — 4 params
        // v12 type-hints StandaloneView; v13 uses StandaloneView|ViewInterface union
        $viewParamType = $constructor->getParameters()[1]->getType();
        $viewMock = $viewParamType instanceof ReflectionUnionType
            ? $this->createMock(ViewInterface::class)
            : $this->createMock($viewParamType->getName());

        return new ModifyPageLayoutOnLoginProviderSelectionEvent(
            $this->createMock(LoginController::class),
            $viewMock,
            $this->createMock(PageRenderer::class),
            $this->createMock(ServerRequestInterface::class),
        );
    }

    private function setUpConfigService(
        string $rpId = 'example.com',
        string $origin = 'https://example.com',
        bool $discoverableEnabled = false,
    ): void {
        $config = new ExtensionConfiguration(
            rpId: $rpId,
            discoverableLoginEnabled: $discoverableEnabled,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn($rpId);

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn($origin);
    }

    /**
     * Expect the injected window.NrPasskeysBeConfig to carry $expected under $key.
     */
    private function expectInjectedConfigValue(string $key, mixed $expected): void
    {
        $this->pageRenderer->method('loadJavaScriptModule');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                self::anything(),
                self::callback(static function (string $code) use ($key, $expected): bool {
                    $jsonPart = \rtrim(\str_replace('window.NrPasskeysBeConfig = ', '', $code), ';');

                    $decoded = \json_decode($jsonPart, true);
                    self::assertIsArray($decoded);
                    self::assertSame($expected, $decoded[$key]);

                    return true;
                }),
            );
    }

    #[Test]
    public function constructorAcceptsRequiredDependencies(): void
    {
        self::assertInstanceOf(InjectPasskeyLoginFields::class, $this->subject);
    }

    #[Test]
    public function invokeLoadsJavaScriptModule(): void
    {
        $this->setUpConfigService();

        $this->pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyLogin.js');

        $this->pageRenderer
            ->method('addJsInlineCode');

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeAddsThemeAwareCssFile(): void
    {
        $this->setUpConfigService();

        $this->pageRenderer
            ->expects(self::once())
            ->method('addCssFile')
            ->with('EXT:nr_passkeys_be/Resources/Public/Css/backend.css');

        $this->pageRenderer
            ->method('loadJavaScriptModule');

        $this->pageRenderer
            ->method('addJsInlineCode');

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeAddsInlineConfigScript(): void
    {
        $this->setUpConfigService(
            rpId: 'test.example.com',
            origin: 'https://test.example.com',
            discoverableEnabled: true,
        );

        $this->pageRenderer
            ->method('loadJavaScriptModule');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                'nr-passkeys-be-config',
                self::callback(static function (string $code): bool {
                    self::assertStringContainsString('window.NrPasskeysBeConfig', $code);

                    // Extract and decode JSON from the JS assignment
                    $jsonPart = \str_replace('window.NrPasskeysBeConfig = ', '', $code);
                    $jsonPart = \rtrim($jsonPart, ';');

                    $decoded = \json_decode($jsonPart, true);
                    self::assertIsArray($decoded);
                    self::assertSame('/typo3/passkeys/login/options', $decoded['loginOptionsUrl']);
                    self::assertSame('test.example.com', $decoded['rpId']);
                    self::assertSame('https://test.example.com', $decoded['origin']);
                    self::assertTrue($decoded['discoverableEnabled']);

                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeInjectsTranslatedUiLabels(): void
    {
        $this->setUpConfigService();

        $this->pageRenderer->method('loadJavaScriptModule');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                'nr-passkeys-be-config',
                self::callback(static function (string $code): bool {
                    $jsonPart = \rtrim(\str_replace('window.NrPasskeysBeConfig = ', '', $code), ';');
                    $decoded = \json_decode($jsonPart, true);
                    self::assertIsArray($decoded);
                    // Login screen is pre-auth, so UI strings are injected as labels
                    // (with English fallbacks) rather than via TYPO3.lang (I18N-1/L10N-1).
                    self::assertArrayHasKey('labels', $decoded);
                    self::assertIsArray($decoded['labels']);

                    foreach (['signIn', 'errorUnsupported', 'errorRateLimit', 'errorNotAllowed', 'helpTitle'] as $key) {
                        self::assertArrayHasKey($key, $decoded['labels']);
                        self::assertNotSame('', $decoded['labels'][$key]);
                    }

                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeUsesEffectiveRpIdFromConfigService(): void
    {
        $this->setUpConfigService(rpId: 'fallback.example.com');

        $this->expectInjectedConfigValue('rpId', 'fallback.example.com');

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeUsesEffectiveOriginFromConfigService(): void
    {
        $this->setUpConfigService(origin: 'https://custom-origin.example.com');

        $this->expectInjectedConfigValue('origin', 'https://custom-origin.example.com');

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokePassesDiscoverableEnabledFalse(): void
    {
        $this->setUpConfigService(discoverableEnabled: false);

        $this->expectInjectedConfigValue('discoverableEnabled', false);

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokePassesLoginOptionsUrl(): void
    {
        $this->setUpConfigService();

        $this->expectInjectedConfigValue('loginOptionsUrl', '/typo3/passkeys/login/options');

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeUsesInjectedPageRendererNotEventPageRenderer(): void
    {
        $this->setUpConfigService();

        // The injected PageRenderer should receive the calls
        $this->pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode');

        ($this->subject)($this->createEvent());
    }
}
