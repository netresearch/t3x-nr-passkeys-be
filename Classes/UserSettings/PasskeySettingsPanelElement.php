<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\UserSettings;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * FormEngine render element for the passkey management panel in User Settings.
 *
 * Registered as renderType 'nrPasskeySettingsPanel' and used on TYPO3 14+.
 * TYPO3 12/13 still uses the userFunc approach via PasskeySettingsPanel.
 *
 * HTML generation is delegated to PasskeySettingsPanel::buildHtml() to avoid
 * duplication between the userFunc and FormEngine code paths.
 *
 * @internal
 */
final class PasskeySettingsPanelElement extends AbstractFormElement
{
    use TranslationTrait;

    public function __construct(
        private readonly CredentialRepository $credentialRepository,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly PasskeySettingsPanel $panel,
    ) {}

    /**
     * Required for TYPO3 v12 compatibility: NodeFactory uses method_exists() to
     * choose between the DI path (setData) and the legacy constructor path.
     *
     * @param array<string, mixed> $data
     * @see PasskeyInfoElement::setData()
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        /** @var array<string, mixed> $resultArray */
        $resultArray = $this->initializeResultArray();

        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            return $resultArray;
        }

        $rawUid = $backendUser->user['uid'] ?? null;
        $userId = \is_numeric($rawUid) ? (int) $rawUid : 0;

        if ($userId === 0) {
            return $resultArray;
        }

        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? ($typo3Conf['SYS'] ?? null) : null;
        $encryptionKey = \is_array($sysConf) && \is_string($sysConf['encryptionKey'] ?? null)
            ? $sysConf['encryptionKey']
            : '';

        if (\strlen($encryptionKey) < 32) {
            $warning = $this->translate(
                'manage.warning.encryptionKey',
                'Passkey management is unavailable. The TYPO3 encryption key is missing or too short (minimum 32 characters). Configure it in Admin Tools > Settings > Configure Installation-Wide Options.',
            );
            $resultArray['html'] = '<div class="alert alert-danger">' . \htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</div>';

            return $resultArray;
        }

        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-passkeys-be/PasskeyManagement.js');
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf',
            'js.',
        );

        $passkeyCount = $this->credentialRepository->countByBeUser($userId);

        $urls = [
            'list'            => (string) $this->uriBuilder->buildUriFromRoute('ajax_passkeys_manage_list'),
            'registerOptions' => (string) $this->uriBuilder->buildUriFromRoute('ajax_passkeys_manage_registration_options'),
            'registerVerify'  => (string) $this->uriBuilder->buildUriFromRoute('ajax_passkeys_manage_registration_verify'),
            'rename'          => (string) $this->uriBuilder->buildUriFromRoute('ajax_passkeys_manage_rename'),
            'remove'          => (string) $this->uriBuilder->buildUriFromRoute('ajax_passkeys_manage_remove'),
        ];

        $resultArray['html'] = $this->panel->buildHtml($passkeyCount, $urls);

        return $resultArray;
    }
}
