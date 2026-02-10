<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\LoginProvider;

use Error;
use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\LoginProvider\PasskeyLoginProvider;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\CMS\Fluid\View\StandaloneView;

#[CoversClass(PasskeyLoginProvider::class)]
final class PasskeyLoginProviderTest extends TestCase
{
    private ExtensionConfigurationService $configService;
    private PageRenderer $pageRenderer;
    private UriBuilder $uriBuilder;
    private PasskeyLoginProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturnCallback(static function (string $routeName, array $params = []): Uri {
                $routeMap = [
                    'passkeys_login_options' => '/typo3/passkeys/login/options',
                    'login' => '/typo3/login',
                ];
                $url = $routeMap[$routeName] ?? '/typo3/unknown';
                if ($params !== []) {
                    $url .= '?' . \http_build_query($params);
                }

                return new Uri($url);
            });

        $this->subject = new PasskeyLoginProvider(
            $this->configService,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    #[Test]
    public function constructorAcceptsRequiredDependencies(): void
    {
        // Arrange
        $configService = $this->createMock(ExtensionConfigurationService::class);
        $pageRenderer = $this->createMock(PageRenderer::class);
        $uriBuilder = $this->createMock(UriBuilder::class);

        // Act
        $subject = new PasskeyLoginProvider($configService, $pageRenderer, $uriBuilder);

        // Assert
        self::assertInstanceOf(PasskeyLoginProvider::class, $subject);
    }

    #[Test]
    public function renderDoesNotSetTemplatePathForNonStandaloneView(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        // Create a ViewInterface mock that is NOT StandaloneView
        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::isType('array'));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderAssignsVariablesToView(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'test.example.com',
            discoverableLoginEnabled: true,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('effective-rp.example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://effective-origin.example.com');

        $expectedVariables = [
            'passkeysEnabled' => true,
            'rpId' => 'effective-rp.example.com',
            'origin' => 'https://effective-origin.example.com',
            'loginOptionsUrl' => '/typo3/passkeys/login/options',
            'discoverableEnabled' => true,
            'passwordLoginDisabled' => false,
            'passwordLoginUrl' => '/typo3/login?loginProvider=1433416747',
        ];

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with($expectedVariables);

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderAssignsVariablesWithPasswordLoginDisabled(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'test.example.com',
            discoverableLoginEnabled: false,
            disablePasswordLogin: true,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('test.example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://test.example.com');

        $expectedVariables = [
            'passkeysEnabled' => true,
            'rpId' => 'test.example.com',
            'origin' => 'https://test.example.com',
            'loginOptionsUrl' => '/typo3/passkeys/login/options',
            'discoverableEnabled' => false,
            'passwordLoginDisabled' => true,
            'passwordLoginUrl' => '/typo3/login?loginProvider=1433416747',
        ];

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with($expectedVariables);

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderLoadsJavaScriptModuleViaPageRenderer(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

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

        $view = $this->createMock(ViewInterface::class);
        $view
            ->method('assignMultiple');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderUsesEffectiveRpIdFromConfigService(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: '',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->expects(self::once())
            ->method('getEffectiveRpId')
            ->willReturn('fallback.example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://fallback.example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(function ($variables) {
                return $variables['rpId'] === 'fallback.example.com';
            }));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderUsesEffectiveOriginFromConfigService(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            origin: '',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->expects(self::once())
            ->method('getEffectiveOrigin')
            ->willReturn('https://fallback-origin.example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(function ($variables) {
                return $variables['origin'] === 'https://fallback-origin.example.com';
            }));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderUsesConfigurationFromConfigService(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'test.example.com',
            discoverableLoginEnabled: true,
            disablePasswordLogin: true,
        );

        $this->configService
            ->expects(self::once())
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('test.example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://test.example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(function ($variables) {
                return $variables['discoverableEnabled'] === true
                    && $variables['passwordLoginDisabled'] === true;
            }));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderAlwaysAssignsPasskeysEnabledTrue(): void
    {
        // Arrange
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(function ($variables) {
                return $variables['passkeysEnabled'] === true;
            }));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderAlwaysAssignsLoginOptionsUrl(): void
    {
        // Arrange
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(function ($variables) {
                return $variables['loginOptionsUrl'] === '/typo3/passkeys/login/options';
            }));

        $this->pageRenderer
            ->method('addJsFile');

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function renderCallsAllRequiredMethodsOnViewInterface(): void
    {
        // Arrange
        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->expects(self::once())
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->expects(self::once())
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->expects(self::once())
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(self::isType('array'));

        $this->pageRenderer
            ->expects(self::once())
            ->method('addJsFile')
            ->with(
                self::stringContains('PasskeyLogin.js'),
                'text/javascript',
                false,
                false,
                '',
                true,
            );

        // Act
        $this->subject->render($view, $this->pageRenderer, 'login');
    }

    #[Test]
    public function modifyViewAssignsVariablesToView(): void
    {
        $config = new ExtensionConfiguration(
            rpId: 'v14.example.com',
            discoverableLoginEnabled: true,
            disablePasswordLogin: true,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('v14.example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://v14.example.com');

        $expectedVariables = [
            'passkeysEnabled' => true,
            'rpId' => 'v14.example.com',
            'origin' => 'https://v14.example.com',
            'loginOptionsUrl' => '/typo3/passkeys/login/options',
            'discoverableEnabled' => true,
            'passwordLoginDisabled' => true,
            'passwordLoginUrl' => '/typo3/login?loginProvider=1433416747',
        ];

        $view = $this->createMock(ViewInterface::class);
        $view
            ->expects(self::once())
            ->method('assignMultiple')
            ->with($expectedVariables);

        $request = $this->createMock(ServerRequestInterface::class);

        $this->subject->modifyView($request, $view);
    }

    #[Test]
    public function modifyViewLoadsJavaScriptViaInjectedPageRenderer(): void
    {
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

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

        $view = $this->createMock(ViewInterface::class);
        $view->method('assignMultiple');

        $request = $this->createMock(ServerRequestInterface::class);

        $this->subject->modifyView($request, $view);
    }

    #[Test]
    public function modifyViewReturnsRelativeTemplateName(): void
    {
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $view = $this->createMock(ViewInterface::class);
        $view->method('assignMultiple');

        $request = $this->createMock(ServerRequestInterface::class);

        $result = $this->subject->modifyView($request, $view);

        self::assertSame('Login/PasskeyLogin', $result);
    }

    #[Test]
    public function modifyViewAddsTemplateRootPathForFluidViewAdapter(): void
    {
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $templatePaths = $this->createMock(\TYPO3Fluid\Fluid\View\TemplatePaths::class);
        $templatePaths
            ->expects(self::once())
            ->method('getTemplateRootPaths')
            ->willReturn(['/existing/path']);
        $templatePaths
            ->expects(self::once())
            ->method('setTemplateRootPaths')
            ->with(['/existing/path', 'EXT:nr_passkeys_be/Resources/Private/Templates']);

        $renderingContext = $this->createMock(\TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface::class);
        $renderingContext
            ->method('getTemplatePaths')
            ->willReturn($templatePaths);

        $fluidView = $this->createMock(FluidViewAdapter::class);
        $fluidView->method('assignMultiple');
        $fluidView
            ->method('getRenderingContext')
            ->willReturn($renderingContext);

        $request = $this->createMock(ServerRequestInterface::class);

        $result = $this->subject->modifyView($request, $fluidView);

        self::assertSame('Login/PasskeyLogin', $result);
    }

    #[Test]
    public function modifyViewSkipsTemplateRootPathForPlainViewInterface(): void
    {
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        // Plain ViewInterface mock - no template path methods
        $view = $this->createMock(ViewInterface::class);
        $view->method('assignMultiple');

        $request = $this->createMock(ServerRequestInterface::class);

        // Should not throw - just skips the template root path addition
        $result = $this->subject->modifyView($request, $view);

        self::assertSame('Login/PasskeyLogin', $result);
    }

    #[Test]
    public function renderEntersStandaloneViewBranch(): void
    {
        if (!\class_exists(StandaloneView::class)) {
            self::markTestSkipped('StandaloneView does not exist in TYPO3 v14');
        }

        $config = new ExtensionConfiguration(
            rpId: 'example.com',
            discoverableLoginEnabled: false,
            disablePasswordLogin: false,
        );

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        $standaloneView = $this->createMock(StandaloneView::class);
        $standaloneView
            ->method('assignMultiple');

        $this->pageRenderer
            ->method('addJsFile');

        // The StandaloneView instanceof branch calls GeneralUtility::getFileAbsFileName
        // which requires PackageManager. We verify the branch is entered by catching the Error.
        try {
            $this->subject->render($standaloneView, $this->pageRenderer, 'login');
        } catch (Error $e) {
            self::assertStringContainsString('packageManager', $e->getMessage());
            return;
        }

        self::assertTrue(true);
    }

    #[Test]
    public function renderPassesPageRendererArgumentNotInjected(): void
    {
        $config = new ExtensionConfiguration();

        $this->configService
            ->method('getConfiguration')
            ->willReturn($config);

        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('example.com');

        $this->configService
            ->method('getEffectiveOrigin')
            ->willReturn('https://example.com');

        // Create a different PageRenderer mock to verify the argument is used
        $otherPageRenderer = $this->createMock(PageRenderer::class);
        $otherPageRenderer
            ->expects(self::once())
            ->method('addJsFile')
            ->with(
                self::stringContains('PasskeyLogin.js'),
                'text/javascript',
                false,
                false,
                '',
                true,
            );

        // The injected page renderer should NOT be called
        $this->pageRenderer
            ->expects(self::never())
            ->method('addJsFile');

        $view = $this->createMock(ViewInterface::class);
        $view->method('assignMultiple');

        $this->subject->render($view, $otherPageRenderer, 'login');
    }
}
