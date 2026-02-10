<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\EventListener;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsEventListener(identifier: 'nr-passkeys-be/inject-passkey-login-fields')]
final readonly class InjectPasskeyLoginFields
{
    public function __construct(
        private ExtensionConfigurationService $configService,
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyPageLayoutOnLoginProviderSelectionEvent $event): void
    {
        $config = $this->configService->getConfiguration();

        $this->pageRenderer->addJsFile(
            'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyLogin.js',
            'text/javascript',
            false,
            false,
            '',
            true,
        );

        $passkeyConfig = [
            'loginOptionsUrl' => (string) $this->uriBuilder->buildUriFromRoute('passkeys_login_options'),
            'rpId' => $this->configService->getEffectiveRpId(),
            'origin' => $this->configService->getEffectiveOrigin(),
            'discoverableEnabled' => $config->isDiscoverableLoginEnabled(),
        ];

        $this->pageRenderer->addJsInlineCode(
            'nr-passkeys-be-config',
            'window.NrPasskeysBeConfig = ' . \json_encode($passkeyConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) . ';',
            false,
            true,
            true,
        );
    }
}
