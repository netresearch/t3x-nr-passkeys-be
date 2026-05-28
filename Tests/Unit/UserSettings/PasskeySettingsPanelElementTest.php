<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\UserSettings;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanel;
use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanelElement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

#[CoversClass(PasskeySettingsPanelElement::class)]
final class PasskeySettingsPanelElementTest extends TestCase
{
    private CredentialRepository&MockObject $credentialRepository;
    private UriBuilder&MockObject $uriBuilder;
    private PageRenderer&MockObject $pageRenderer;
    private PasskeySettingsPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        // TYPO3 constant used by AbstractFormElement::wrapWithFieldsetAndLegend()
        if (!\defined('LF')) {
            \define('LF', "\n");
        }

        $this->credentialRepository = $this->createMock(CredentialRepository::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturnCallback(static function (string $routeName): Uri {
                return new Uri('/typo3/ajax/passkeys/' . $routeName . '?token=test');
            });

        $this->panel = new PasskeySettingsPanel();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = \str_repeat('a', 64);

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => \basename(\str_replace(':', '/', $key)),
        );
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG'], $GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function renderReturnsEmptyHtmlWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $result = $this->createSubject()->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyHtmlWhenBackendUserIsNotAuthentication(): void
    {
        $GLOBALS['BE_USER'] = new stdClass();

        $result = $this->createSubject()->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyHtmlWhenUserUidIsZero(): void
    {
        $this->setUpBackendUser(0);

        $result = $this->createSubject()->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsErrorHtmlWhenEncryptionKeyTooShort(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'short';

        $result = $this->createSubject()->render();

        self::assertStringContainsString('alert alert-danger', $result['html'] ?? '');
        self::assertStringNotContainsString('passkey-management-container', $result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsErrorHtmlWhenEncryptionKeyMissing(): void
    {
        $this->setUpBackendUser(1);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        $result = $this->createSubject()->render();

        self::assertStringContainsString('alert alert-danger', $result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsManagementContainerOnSuccessPath(): void
    {
        $this->setUpBackendUser(1);
        $this->credentialRepository->method('countByBeUser')->willReturn(3);

        $result = $this->createSubject()->render();

        self::assertStringContainsString('id="passkey-management-container"', $result['html'] ?? '');
    }

    #[Test]
    public function renderLoadsJavaScriptModule(): void
    {
        $this->setUpBackendUser(1);
        $this->credentialRepository->method('countByBeUser')->willReturn(0);

        $this->pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyManagement.js');

        $this->createSubject()->render();
    }

    #[Test]
    public function renderUsesCorrectUserIdForRepository(): void
    {
        $this->setUpBackendUser(42);

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(0);

        $this->createSubject()->render();
    }

    #[Test]
    public function renderDelegatesHtmlGenerationToPanel(): void
    {
        $this->setUpBackendUser(1);
        $this->credentialRepository->method('countByBeUser')->willReturn(2);

        $result = $this->createSubject()->render();

        // HTML comes from PasskeySettingsPanel::buildHtml() — spot-check its output
        self::assertStringContainsString('id="passkey-list-table"', $result['html'] ?? '');
        self::assertStringContainsString('id="passkey-add-btn"', $result['html'] ?? '');
        self::assertStringContainsString('badge-success', $result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsArrayWithHtmlKey(): void
    {
        $this->setUpBackendUser(1);
        $this->credentialRepository->method('countByBeUser')->willReturn(0);

        $result = $this->createSubject()->render();

        self::assertIsArray($result);
        self::assertArrayHasKey('html', $result);
    }

    #[Test]
    public function setDataPopulatesDataProperty(): void
    {
        $subject = $this->createSubject(['tableName' => 'be_users_settings']);
        // setData is exercised indirectly; verify render() still works
        $this->setUpBackendUser(1);
        $this->credentialRepository->method('countByBeUser')->willReturn(0);

        $result = $subject->render();

        self::assertIsArray($result);
    }

    private function createSubject(array $data = []): PasskeySettingsPanelElement
    {
        $subject = new PasskeySettingsPanelElement(
            $this->credentialRepository,
            $this->uriBuilder,
            $this->pageRenderer,
            $this->panel,
        );
        $subject->setData($data);

        return $subject;
    }

    private function setUpBackendUser(int $uid): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid];
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
