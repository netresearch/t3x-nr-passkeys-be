<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\LoginProvider;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewInterface;
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
     * TYPO3 v14+ login provider method (replaces render()).
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

        return 'EXT:nr_passkeys_be/Resources/Private/Templates/Login/PasskeyLogin.html';
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
        ]);
    }
}
