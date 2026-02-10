<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\LoginProvider;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\CMS\Fluid\View\StandaloneView;

class PasskeyLoginProvider implements LoginProviderInterface
{
    public function __construct(
        private readonly ExtensionConfigurationService $configService,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function render(ViewInterface|StandaloneView $view, PageRenderer $pageRenderer, $loginType): void
    {
        $this->assignViewVariables($view);

        $pageRenderer->addJsFile(
            'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyLogin.js',
            'text/javascript',
            false,
            false,
            '',
            true,
        );

        if ($view instanceof StandaloneView) {
            $view->setTemplatePathAndFilename(
                GeneralUtility::getFileAbsFileName(
                    'EXT:nr_passkeys_be/Resources/Private/Templates/Login/PasskeyLogin.html',
                ),
            );
        }
    }

    /**
     * TYPO3 v13.4+ login provider method (replaces render()).
     *
     * Returns a template name relative to the template root paths.
     * The extension's template root path is added to the view so
     * that Fluid can resolve Login/PasskeyLogin.html.
     */
    public function modifyView(ServerRequestInterface $request, ViewInterface $view): string
    {
        $this->assignViewVariables($view);

        $this->pageRenderer->addJsFile(
            'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyLogin.js',
            'text/javascript',
            false,
            false,
            '',
            true,
        );

        // Add extension template root path so the view can resolve our template.
        // TYPO3's TemplatePaths::ensureAbsolutePath() resolves EXT: notation.
        if ($view instanceof FluidViewAdapter) {
            $templatePaths = $view->getRenderingContext()->getTemplatePaths();
            $existingPaths = $templatePaths->getTemplateRootPaths();
            $existingPaths[] = 'EXT:nr_passkeys_be/Resources/Private/Templates';
            $templatePaths->setTemplateRootPaths($existingPaths);
        }

        return 'Login/PasskeyLogin';
    }

    private function assignViewVariables(ViewInterface|StandaloneView $view): void
    {
        $config = $this->configService->getConfiguration();

        $view->assignMultiple([
            'passkeysEnabled' => true,
            'rpId' => $this->configService->getEffectiveRpId(),
            'origin' => $this->configService->getEffectiveOrigin(),
            'loginOptionsUrl' => '/typo3/passkeys/login/options',
            'discoverableEnabled' => $config->isDiscoverableLoginEnabled(),
            'passwordLoginDisabled' => $config->isDisablePasswordLogin(),
            'passwordLoginUrl' => '/typo3/login?loginProvider=1433416747',
        ]);
    }
}
