<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\UserSettings;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Renders the passkey management panel in User Settings (Setup module).
 *
 * Registered via $GLOBALS['TYPO3_USER_SETTINGS']['columns']['passkeys']
 * as a 'type' => 'user' field with userFunc callback.
 *
 * Note: Dependencies are resolved via GeneralUtility::makeInstance() because
 * TYPO3's callUserFunction() does not use the DI container for instantiation.
 */
final class PasskeySettingsPanel
{
    private const LLL_PREFIX = 'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:';

    /**
     * Render the passkey management panel.
     *
     * Called by TYPO3's GeneralUtility::callUserFunction() from the Setup module.
     *
     * @param array<string, mixed> $params Parameters from the user settings form
     * @return string HTML output
     */
    public function render(array $params): string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return '';
        }

        $rawUid = $backendUser->user['uid'] ?? null;
        $userId = \is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($userId === 0) {
            return '';
        }

        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? ($typo3Conf['SYS'] ?? null) : null;
        $encryptionKey = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null)
            ? $sysConf['encryptionKey']
            : '';
        if (\strlen($encryptionKey) < 32) {
            $lang = $this->getLanguageService();
            $warning = $this->translate(
                $lang,
                'manage.warning.encryptionKey',
                'Passkey management is unavailable. The TYPO3 encryption key is missing or too short (minimum 32 characters). Configure it in Admin Tools > Settings > Configure Installation-Wide Options.',
            );

            return '<div class="alert alert-danger">' . \htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addJsFile(
            'EXT:nr_passkeys_be/Resources/Public/JavaScript/PasskeyManagement.js',
            'text/javascript',
            false,
            false,
            '',
            true,
        );

        $credentialRepository = GeneralUtility::makeInstance(CredentialRepository::class);
        $passkeyCount = $credentialRepository->countByBeUser($userId);

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $urls = [
            'list' => (string) $uriBuilder->buildUriFromRoute('ajax_passkeys_manage_list'),
            'registerOptions' => (string) $uriBuilder->buildUriFromRoute('ajax_passkeys_manage_registration_options'),
            'registerVerify' => (string) $uriBuilder->buildUriFromRoute('ajax_passkeys_manage_registration_verify'),
            'rename' => (string) $uriBuilder->buildUriFromRoute('ajax_passkeys_manage_rename'),
            'remove' => (string) $uriBuilder->buildUriFromRoute('ajax_passkeys_manage_remove'),
        ];

        return $this->buildHtml($passkeyCount, $urls);
    }

    /**
     * @param array<string, string> $urls Token-protected backend route URLs
     */
    private function buildHtml(int $passkeyCount, array $urls): string
    {
        $lang = $this->getLanguageService();

        $title = $this->translate($lang, 'manage.title', 'Passkeys');
        $description = $this->translate($lang, 'manage.description', 'Manage your registered passkeys for passwordless login.');
        $addLabel = $this->translate($lang, 'manage.add', 'Add Passkey');
        $nameLabel = $this->translate($lang, 'manage.label.name', 'Name');
        $createdLabel = $this->translate($lang, 'manage.label.created', 'Created');
        $lastUsedLabel = $this->translate($lang, 'manage.label.lastUsed', 'Last Used');
        $actionsLabel = $this->translate($lang, 'manage.label.actions', 'Actions');
        $singleKeyWarning = $this->translate($lang, 'manage.warning.singleKey', 'You only have one passkey registered. Consider adding a backup passkey.');
        $noPasskeys = $this->translate($lang, 'manage.noPasskeys', 'No passkeys registered yet.');

        $countBadgeClass = match (true) {
            $passkeyCount === 0 => 'badge-warning',
            $passkeyCount === 1 => 'badge-info',
            default => 'badge-success',
        };

        $listUrl = \htmlspecialchars($urls['list'], ENT_QUOTES, 'UTF-8');
        $registerOptionsUrl = \htmlspecialchars($urls['registerOptions'], ENT_QUOTES, 'UTF-8');
        $registerVerifyUrl = \htmlspecialchars($urls['registerVerify'], ENT_QUOTES, 'UTF-8');
        $renameUrl = \htmlspecialchars($urls['rename'], ENT_QUOTES, 'UTF-8');
        $removeUrl = \htmlspecialchars($urls['remove'], ENT_QUOTES, 'UTF-8');

        $title = \htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $description = \htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $addLabel = \htmlspecialchars($addLabel, ENT_QUOTES, 'UTF-8');
        $nameLabel = \htmlspecialchars($nameLabel, ENT_QUOTES, 'UTF-8');
        $createdLabel = \htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8');
        $lastUsedLabel = \htmlspecialchars($lastUsedLabel, ENT_QUOTES, 'UTF-8');
        $actionsLabel = \htmlspecialchars($actionsLabel, ENT_QUOTES, 'UTF-8');
        $singleKeyWarning = \htmlspecialchars($singleKeyWarning, ENT_QUOTES, 'UTF-8');
        $noPasskeys = \htmlspecialchars($noPasskeys, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div id="passkey-management-container"
     data-list-url="{$listUrl}"
     data-register-options-url="{$registerOptionsUrl}"
     data-register-verify-url="{$registerVerifyUrl}"
     data-rename-url="{$renameUrl}"
     data-remove-url="{$removeUrl}">
    <h4>{$title} <span class="badge {$countBadgeClass}" id="passkey-count">{$passkeyCount}</span></h4>
    <p class="text-body-secondary">{$description}</p>
    <div id="passkey-message" class="alert d-none" role="alert"></div>
    <div id="passkey-single-warning" class="alert alert-warning d-none">{$singleKeyWarning}</div>
    <div class="mb-3">
        <button type="button" id="passkey-add-btn" class="btn btn-primary btn-sm">{$addLabel}</button>
    </div>
    <div id="passkey-empty" class="alert alert-info d-none">{$noPasskeys}</div>
    <table class="table table-hover" id="passkey-list-table">
        <thead>
            <tr>
                <th>{$nameLabel}</th>
                <th>{$createdLabel}</th>
                <th>{$lastUsedLabel}</th>
                <th>{$actionsLabel}</th>
            </tr>
        </thead>
        <tbody id="passkey-list-body"></tbody>
    </table>
</div>
HTML;
    }

    private function translate(LanguageService $lang, string $key, string $fallback): string
    {
        $translated = $lang->sL(self::LLL_PREFIX . $key);

        return $translated !== '' ? $translated : $fallback;
    }

    private function getLanguageService(): LanguageService
    {
        $lang = $GLOBALS['LANG'] ?? null;
        \assert($lang instanceof LanguageService);

        return $lang;
    }
}
