<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\UserSettings;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
    use TranslationTrait;

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
        $sysConf = \is_array($typo3Conf) ? $typo3Conf['SYS'] ?? null : null;
        $encryptionKey = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null) ? $sysConf['encryptionKey'] : '';

        if (\strlen($encryptionKey) < 32) {
            $warning = $this->translate(
                'manage.warning.encryptionKey',
                'Passkey management is unavailable. The TYPO3 encryption key is missing or too short (minimum 32 characters). Configure it in Admin Tools > Settings > Configure Installation-Wide Options.',
            );

            return '<div class="alert alert-danger">' . \htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadJavaScriptModule('@netresearch/nr-passkeys-be/PasskeyManagement.js');
        $pageRenderer->addInlineLanguageLabelFile('EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf', 'js.');

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
    public function buildHtml(int $passkeyCount, array $urls): string
    {
        $infoText = $this->translate(
            'manage.info.passkeys',
            'Passkeys replace your password with biometric or device-based authentication (fingerprint, face, security key). We recommend registering at least two passkeys for backup — for example, your laptop and your phone.',
        );
        $title = $this->translate('manage.title', 'Passkeys');
        $description = $this->translate('manage.description', 'Manage your registered passkeys for passwordless login.');
        $addLabel = $this->translate('manage.add', 'Add Passkey');
        $nameLabel = $this->translate('manage.label.name', 'Name');
        $createdLabel = $this->translate('manage.label.created', 'Created');
        $lastUsedLabel = $this->translate('manage.label.lastUsed', 'Last Used');
        $actionsLabel = $this->translate('manage.label.actions', 'Actions');
        $singleKeyWarning = $this->translate(
            'manage.warning.singleKey',
            'You only have one passkey registered. Consider adding a backup passkey.',
        );
        $noPasskeys = $this->translate('manage.noPasskeys', 'No passkeys registered yet.');
        $nameHelp = $this->translate(
            'manage.label.name.help',
            'A descriptive label to identify this passkey (e.g. "MacBook TouchID", "YubiKey").',
        );
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
        $nameHelp = \htmlspecialchars($nameHelp, ENT_QUOTES, 'UTF-8');
        $infoText = \htmlspecialchars($infoText, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <style>.passkey-name-input{max-width:200px}</style>
        <div class="alert alert-info">{$infoText}</div>
        <div id="passkey-management-container"
             data-list-url="{$listUrl}"
             data-register-options-url="{$registerOptionsUrl}"
             data-register-verify-url="{$registerVerifyUrl}"
             data-rename-url="{$renameUrl}"
             data-remove-url="{$removeUrl}">
            <h4>{$title} <span class="badge {$countBadgeClass}" id="passkey-count">{$passkeyCount}</span></h4>
            <p class="text-body-secondary">{$description}</p>
            <div id="passkey-single-warning" class="alert alert-warning d-none" role="status" aria-live="polite">{$singleKeyWarning}</div>
            <div class="mb-3">
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="passkey-name-input" class="form-control form-control-sm passkey-name-input" value="Passkey" maxlength="128" placeholder="{$nameLabel}" aria-label="{$nameLabel}" aria-describedby="passkey-name-help" />
                    <button type="button" id="passkey-add-btn" class="btn btn-primary btn-sm">{$addLabel}</button>
                </div>
                <small id="passkey-name-help" class="form-text text-body-secondary">{$nameHelp}</small>
            </div>
            <div id="passkey-empty" class="alert alert-info d-none" role="status" aria-live="polite">{$noPasskeys}</div>
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
}
