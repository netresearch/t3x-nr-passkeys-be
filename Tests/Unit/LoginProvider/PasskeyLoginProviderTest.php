<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\LoginProvider;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration;
use Netresearch\NrPasskeysBe\LoginProvider\PasskeyLoginProvider;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

#[CoversClass(PasskeyLoginProvider::class)]
final class PasskeyLoginProviderTest extends TestCase
{
    private ExtensionConfigurationService $configService;
    private PageRenderer $pageRenderer;
    private PasskeyLoginProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $this->subject = new PasskeyLoginProvider(
            $this->configService,
            $this->pageRenderer,
        );
    }

    #[Test]
    public function constructorAcceptsRequiredDependencies(): void
    {
        // Arrange
        $configService = $this->createMock(ExtensionConfigurationService::class);
        $pageRenderer = $this->createMock(PageRenderer::class);

        // Act
        $subject = new PasskeyLoginProvider($configService, $pageRenderer);

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
    public function modifyViewReturnsTemplatePath(): void
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

        self::assertSame(
            'EXT:nr_passkeys_be/Resources/Private/Templates/Login/PasskeyLogin.html',
            $result,
        );
    }
}
