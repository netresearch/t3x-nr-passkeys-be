<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\EventListener\InjectPasskeyLoginFields;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;

#[CoversClass(InjectPasskeyLoginFields::class)]
final class InjectPasskeyLoginFieldsTest extends TestCase
{
    private ExtensionConfigurationService $configService;
    private PageRenderer $pageRenderer;
    private UriBuilder $uriBuilder;
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
        return new ModifyPageLayoutOnLoginProviderSelectionEvent(
            $this->createMock(LoginController::class),
            $this->createMock(ViewInterface::class),
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

    #[Test]
    public function constructorAcceptsRequiredDependencies(): void
    {
        self::assertInstanceOf(InjectPasskeyLoginFields::class, $this->subject);
    }

    #[Test]
    public function invokeAddsJavaScriptFile(): void
    {
        $this->setUpConfigService();

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsFile')
            ->with(
                'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyLogin.js',
                'text/javascript',
                false,
                false,
                '',
                true,
            );

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
            ->method('addJsFile');

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
    public function invokeUsesEffectiveRpIdFromConfigService(): void
    {
        $this->setUpConfigService(rpId: 'fallback.example.com');

        $this->pageRenderer->method('addJsFile');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                self::anything(),
                self::callback(static function (string $code): bool {
                    $jsonPart = \str_replace('window.NrPasskeysBeConfig = ', '', $code);
                    $jsonPart = \rtrim($jsonPart, ';');
                    $decoded = \json_decode($jsonPart, true);
                    self::assertSame('fallback.example.com', $decoded['rpId']);
                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeUsesEffectiveOriginFromConfigService(): void
    {
        $this->setUpConfigService(origin: 'https://custom-origin.example.com');

        $this->pageRenderer->method('addJsFile');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                self::anything(),
                self::callback(static function (string $code): bool {
                    $jsonPart = \str_replace('window.NrPasskeysBeConfig = ', '', $code);
                    $jsonPart = \rtrim($jsonPart, ';');
                    $decoded = \json_decode($jsonPart, true);
                    self::assertSame('https://custom-origin.example.com', $decoded['origin']);
                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokePassesDiscoverableEnabledFalse(): void
    {
        $this->setUpConfigService(discoverableEnabled: false);

        $this->pageRenderer->method('addJsFile');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                self::anything(),
                self::callback(static function (string $code): bool {
                    $jsonPart = \str_replace('window.NrPasskeysBeConfig = ', '', $code);
                    $jsonPart = \rtrim($jsonPart, ';');
                    $decoded = \json_decode($jsonPart, true);
                    self::assertFalse($decoded['discoverableEnabled']);
                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokePassesLoginOptionsUrl(): void
    {
        $this->setUpConfigService();

        $this->pageRenderer->method('addJsFile');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode')
            ->with(
                self::anything(),
                self::callback(static function (string $code): bool {
                    $jsonPart = \str_replace('window.NrPasskeysBeConfig = ', '', $code);
                    $jsonPart = \rtrim($jsonPart, ';');
                    $decoded = \json_decode($jsonPart, true);
                    self::assertSame('/typo3/passkeys/login/options', $decoded['loginOptionsUrl']);
                    return true;
                }),
            );

        ($this->subject)($this->createEvent());
    }

    #[Test]
    public function invokeUsesInjectedPageRendererNotEventPageRenderer(): void
    {
        $this->setUpConfigService();

        // The injected PageRenderer should receive the calls
        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsFile');

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsInlineCode');

        ($this->subject)($this->createEvent());
    }
}
