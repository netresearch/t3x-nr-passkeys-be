<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Form\Element;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * FormEngine element that renders passkey information and management controls
 * in the be_users record editor. Follows the MfaInfoElement pattern.
 *
 * @internal
 */
class PasskeyInfoElement extends AbstractFormElement
{
    public function __construct(
        private readonly CredentialRepository $credentialRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();

        $tableName = $this->data['tableName'] ?? '';
        if ($tableName !== 'be_users') {
            return $resultArray;
        }

        $userId = (int) ($this->data['databaseRow']['uid'] ?? 0);
        if ($userId === 0) {
            return $resultArray;
        }

        $currentBackendUser = $this->getBackendUser();
        $lang = $this->getLanguageService();
        $isAdmin = $currentBackendUser->isAdmin();

        // System maintainer protection: only system maintainers can manage other system maintainers' passkeys.
        // Check the system maintainers list directly to avoid instantiating a BackendUserAuthentication
        // (which requires a database connection for setBeUserByUid).
        $isManagementAllowed = $isAdmin;
        if ($isAdmin) {
            $systemMaintainers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemMaintainers'] ?? [];
            if (\is_array($systemMaintainers) && $systemMaintainers !== []) {
                $systemMaintainerIds = \array_map('\intval', $systemMaintainers);
                $targetIsSystemMaintainer = \in_array($userId, $systemMaintainerIds, true);
                if ($targetIsSystemMaintainer && !$currentBackendUser->isSystemMaintainer()) {
                    $isManagementAllowed = false;
                }
            }
        }

        $credentials = $this->credentialRepository->findAllByBeUser($userId);
        $activeCount = \count(\array_filter(
            $credentials,
            static fn(\Netresearch\NrPasskeysBe\Domain\Model\Credential $credential): bool => !$credential->isRevoked(),
        ));

        $enabledLabel = $lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.enabled');
        $disabledLabel = $lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.disabled');
        $username = (string) ($this->data['databaseRow']['username'] ?? '');

        // Status badge
        if ($activeCount > 0) {
            $badgeText = $activeCount . ' ' . $enabledLabel;
            $status = '<span class="badge badge-success badge-space-end t3js-passkey-status-label mb-2"'
                . ' data-alternative-label="' . \htmlspecialchars($disabledLabel) . '">'
                . \htmlspecialchars($badgeText) . '</span>';
        } else {
            $status = '<span class="badge badge-danger badge-space-end t3js-passkey-status-label"'
                . ' data-alternative-label="' . \htmlspecialchars($enabledLabel) . '">'
                . \htmlspecialchars($disabledLabel) . '</span>';
        }

        $html = [];
        $childHtml = [];

        // Credential list
        if ($credentials !== []) {
            $childHtml[] = '<ul class="list-group t3js-passkey-credentials-list">';
            foreach ($credentials as $credential) {
                $credUid = $credential->getUid();
                $isRevoked = $credential->isRevoked();

                $childHtml[] = '<li class="list-group-item" id="passkey-credential-' . $credUid . '" style="line-height: 2.1em;">';
                $childHtml[] = '<strong>' . \htmlspecialchars($credential->getLabel() ?: 'Passkey #' . $credUid) . '</strong> ';

                if ($isRevoked) {
                    $childHtml[] = '<span class="badge badge-danger">'
                        . \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.status.revoked'))
                        . '</span>';
                } else {
                    $childHtml[] = '<span class="badge badge-success">'
                        . \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.status.active'))
                        . '</span>';
                }

                // Metadata
                $createdAt = $credential->getCreatedAt();
                $lastUsedAt = $credential->getLastUsedAt();
                $createdLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.created'));
                $lastUsedLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.lastUsed'));
                $neverLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.never'));

                $childHtml[] = '<br><small class="text-body-secondary">';
                $childHtml[] = $createdLabel . ': ' . ($createdAt > 0 ? \htmlspecialchars($this->formatTimestamp($createdAt)) : $neverLabel);
                $childHtml[] = ' &middot; ' . $lastUsedLabel . ': ' . ($lastUsedAt > 0 ? \htmlspecialchars($this->formatTimestamp($lastUsedAt)) : $neverLabel);
                $childHtml[] = '</small>';

                // Revoke button for active credentials (admin only)
                if ($isManagementAllowed && !$isRevoked) {
                    $revokeTitle = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke.confirm.title'));
                    $revokeText = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke.confirm.text'));
                    $revokeLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke'));
                    $cancelLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.cancel'));

                    $childHtml[] = '<button type="button"';
                    $childHtml[] = ' class="btn btn-default btn-sm float-end t3js-passkey-revoke-button"';
                    $childHtml[] = ' data-credential-uid="' . $credUid . '"';
                    $childHtml[] = ' data-confirmation-title="' . $revokeTitle . '"';
                    $childHtml[] = ' data-confirmation-content="' . $revokeText . '"';
                    $childHtml[] = ' data-confirmation-cancel-text="' . $cancelLabel . '"';
                    $childHtml[] = ' data-confirmation-revoke-text="' . $revokeLabel . '"';
                    $childHtml[] = ' title="' . $revokeTitle . '"';
                    $childHtml[] = '>';
                    $childHtml[] = $revokeLabel;
                    $childHtml[] = '</button>';
                }

                $childHtml[] = '</li>';
            }
            $childHtml[] = '</ul>';
        }

        $fieldId = 't3js-form-field-passkey-id' . StringUtility::getUniqueId('-');

        $html[] = '<div class="formengine-field-item t3js-formengine-field-item" id="' . \htmlspecialchars($fieldId) . '">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $this->formMaxWidth($this->defaultInputWidth) . 'px">';
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-item-element">';
        $html[] = \implode(PHP_EOL, $childHtml);

        if ($isManagementAllowed) {
            $revokeAllTitle = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll.confirm.title'));
            $revokeAllText = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll.confirm.text'));
            $revokeAllLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll'));
            $cancelLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.cancel'));

            $html[] = '<div class="form-wizards-item-bottom">';

            // "Revoke all passkeys" button
            $html[] = '<button type="button"';
            $html[] = ' class="t3js-passkey-revoke-all-button btn btn-danger mt-2 ' . ($activeCount === 0 ? 'disabled" disabled="disabled' : '') . '"';
            $html[] = ' data-confirmation-title="' . $revokeAllTitle . '"';
            $html[] = ' data-confirmation-content="' . $revokeAllText . '"';
            $html[] = ' data-confirmation-cancel-text="' . $cancelLabel . '"';
            $html[] = ' data-confirmation-revoke-text="' . $revokeAllLabel . '"';
            $html[] = '>';
            $html[] = $revokeAllLabel;
            $html[] = '</button>';

            // "Unlock account" button
            $unlockTitle = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock.confirm.title'));
            $unlockText = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock.confirm.text'));
            $unlockLabel = \htmlspecialchars($lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock'));

            $html[] = '<button type="button"';
            $html[] = ' class="t3js-passkey-unlock-button btn btn-default btn-sm mt-2 ms-2"';
            $html[] = ' data-confirmation-title="' . $unlockTitle . '"';
            $html[] = ' data-confirmation-content="' . $unlockText . '"';
            $html[] = ' data-confirmation-cancel-text="' . $cancelLabel . '"';
            $html[] = ' data-confirmation-unlock-text="' . $unlockLabel . '"';
            $html[] = '>';
            $html[] = $unlockLabel;
            $html[] = '</button>';

            $html[] = '</div>';
        }

        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';

        // Load JavaScript when management is allowed and there's something to interact with
        if ($isManagementAllowed) {
            $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
                '@netresearch/nr-passkeys-be/PasskeyAdminInfo.js',
            )->instance('#' . $fieldId, [
                'userId' => $userId,
                'username' => $username,
            ]);
        }

        $resultArray['html'] = $this->wrapWithFieldsetAndLegend($status . \implode(PHP_EOL, $html));

        return $resultArray;
    }

    private function formatTimestamp(int $timestamp): string
    {
        $format = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'Y-m-d';
        $timeFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i';

        return \date($format . ' ' . $timeFormat, $timestamp);
    }
}
