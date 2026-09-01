<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\EventListener;

use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsEventListener(identifier: 'nr-passkeys-be/inject-passkey-login-fields')]
final readonly class InjectPasskeyLoginFields
{
    use TranslationTrait;

    public function __construct(
        private ExtensionConfigurationService $configService,
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyPageLayoutOnLoginProviderSelectionEvent $event): void
    {
        $config = $this->configService->getConfiguration();
        // The backend login screen ships the importmap (JavaScriptModules.php), so the
        // login module can be loaded as an ES module. window.NrPasskeysBeConfig is set
        // below via a classic inline script, which runs before the deferred module — so
        // the config is available by the time the module's init() runs.
        // Scheme-neutral divider/button styles (currentColor + opacity) so the
        // injected login UI adapts to the light and dark login screens.
        $this->pageRenderer->addCssFile('EXT:nr_passkeys_be/Resources/Public/Css/backend.css');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-passkeys-be/PasskeyLogin.js');
        $passkeyConfig = [
            'loginOptionsUrl' => (string) $this->uriBuilder->buildUriFromRoute('passkeys_login_options'),
            'loginVerifyUrl' => (string) $this->uriBuilder->buildUriFromRoute('passkeys_login_verify'),
            'rpId' => $this->configService->getEffectiveRpId(),
            'origin' => $this->configService->getEffectiveOrigin(),
            'discoverableEnabled' => $config->isDiscoverableLoginEnabled(),
            // The conditional-UI (autofill) ceremony holds its challenge from
            // page load until the user picks a passkey, which can be far later
            // than the challenge lives. PasskeyLogin.js re-arms the ceremony
            // with a fresh challenge before that happens, so it needs the TTL.
            'challengeTtlSeconds' => $config->getChallengeTtlSeconds(),
            // Server-translated UI labels for PasskeyLogin.js (the login screen is
            // pre-authentication, so labels are injected here rather than via
            // addInlineLanguageLabelFile/TYPO3.lang). JS keeps English fallbacks.
            'labels' => [
                'signIn' => $this->translate('login.provider.label', 'Sign in with a passkey'),
                'loading' => $this->translate('login.button.passkey.loading', 'Authenticating…'),
                'errorUnsupported' => $this->translate('login.error.unsupported', 'Your browser does not support Passkeys (WebAuthn).'),
                'errorInsecure' => $this->translate('login.error.insecure', 'Passkeys require a secure connection (HTTPS).'),
                'errorRateLimit' => $this->translate('login.error.rateLimit', 'Too many attempts. Please try again later.'),
                'errorLocked' => $this->translate('login.error.locked', 'Account temporarily locked. Please contact your administrator.'),
                'errorGeneric' => $this->translate('login.error.generic', 'Authentication failed. Please try again.'),
                'errorCancelled' => $this->translate('login.error.cancelled', 'Authentication was cancelled.'),
                'errorNotAllowed' => $this->translate(
                    'login.error.notAllowed',
                    'Authentication was cancelled or no passkey found for this site. Have you registered a passkey?',
                ),
                'errorSecurity' => $this->translate('login.error.security', 'Security error. Please check your connection.'),
                'errorUsernameRequired' => $this->translate('login.error.usernameRequired', 'Please enter your username.'),
                'errorVerifyFailed' => $this->translate(
                    'login.error.verifyFailed',
                    'Passkey authentication failed. Your passkey was not accepted. Please try again or sign in with your password.',
                ),
                'dividerOr' => $this->translate('login.divider.or', 'or'),
                'helpTitle' => $this->translate('login.help.title', 'What are passkeys?'),
                'helpContent' => $this->translate('login.help.content', 'Passkeys are a modern replacement for passwords.'),
                'helpLearnMore' => $this->translate('login.help.learnMore', 'Learn more about passkeys'),
            ],
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
