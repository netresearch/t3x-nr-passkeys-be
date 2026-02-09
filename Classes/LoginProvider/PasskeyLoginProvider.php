<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\LoginProvider;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
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
        $config = $this->configService->getConfiguration();

        // Pass configuration to the template
        $view->assignMultiple([
            'passkeysEnabled' => true,
            'rpId' => $this->configService->getEffectiveRpId(),
            'origin' => $this->configService->getEffectiveOrigin(),
            'loginOptionsUrl' => '/typo3/passkeys/login/options',
            'discoverableEnabled' => $config->isDiscoverableLoginEnabled(),
            'passwordLoginDisabled' => $config->isDisablePasswordLogin(),
        ]);

        // Load the passkey login JavaScript
        $pageRenderer->addJsFile(
            'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyLogin.js',
            'text/javascript',
            false,
            false,
            '',
            true,
        );

        // Set the template for the login form
        if ($view instanceof StandaloneView) {
            $view->setTemplatePathAndFilename(
                GeneralUtility::getFileAbsFileName(
                    'EXT:nr_passkeys_be/Resources/Private/Templates/Login/PasskeyLogin.html',
                ),
            );
        }
    }
}
