<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\UserSettings;

use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(PasskeySettingsPanel::class)]
final class PasskeySettingsPanelTest extends TestCase
{
    private PageRenderer $pageRenderer;
    private CredentialRepository $credentialRepository;
    private UriBuilder $uriBuilder;
    private PasskeySettingsPanel $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->credentialRepository = $this->createMock(CredentialRepository::class);

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturnCallback(static function (string $routeName): Uri {
                $routeMap = [
                    'ajax_passkeys_manage_list' => '/typo3/ajax/passkeys/manage/list?token=test-token',
                    'ajax_passkeys_manage_registration_options' => '/typo3/ajax/passkeys/manage/registration/options?token=test-token',
                    'ajax_passkeys_manage_registration_verify' => '/typo3/ajax/passkeys/manage/registration/verify?token=test-token',
                    'ajax_passkeys_manage_rename' => '/typo3/ajax/passkeys/manage/rename?token=test-token',
                    'ajax_passkeys_manage_remove' => '/typo3/ajax/passkeys/manage/remove?token=test-token',
                ];

                return new Uri($routeMap[$routeName] ?? '/typo3/unknown');
            });

        $this->subject = new PasskeySettingsPanel();

        // Set up a valid encryptionKey so existing tests reach panel rendering
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = \str_repeat('a', 64);

        // Set up language service mock
        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturnCallback(static function (string $key): string {
                $map = [
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.title' => 'Passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.description' => 'Manage your registered passkeys for passwordless login.',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.add' => 'Add Passkey',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.label.name' => 'Name',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.label.created' => 'Created',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.label.lastUsed' => 'Last Used',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.label.actions' => 'Actions',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.warning.singleKey' => 'You only have one passkey registered. Consider adding a backup passkey.',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:manage.noPasskeys' => 'No passkeys registered yet.',
                ];

                return $map[$key] ?? '';
            });
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function renderReturnsEmptyStringWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $result = $this->subject->render([]);

        self::assertSame('', $result);
    }

    #[Test]
    public function renderReturnsEmptyStringWhenBackendUserIsNotAuthentication(): void
    {
        $GLOBALS['BE_USER'] = new stdClass();

        $result = $this->subject->render([]);

        self::assertSame('', $result);
    }

    #[Test]
    public function renderReturnsEmptyStringWhenUserUidIsZero(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 0];
        $GLOBALS['BE_USER'] = $backendUser;

        $result = $this->subject->render([]);

        self::assertSame('', $result);
    }

    #[Test]
    public function renderReturnsHtmlWithPasskeyManagementContainer(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->with(1)
            ->willReturn(3);

        $result = $this->subject->render([]);

        self::assertStringContainsString('id="passkey-management-container"', $result);
    }

    #[Test]
    public function renderLoadsJavaScriptModule(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $this->pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyManagement.js');

        $this->subject->render([]);
    }

    #[Test]
    public function renderIncludesCorrectDataUrlAttributes(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $result = $this->subject->render([]);

        self::assertStringContainsString('data-list-url="/typo3/ajax/passkeys/manage/list?token=test-token"', $result);
        self::assertStringContainsString('data-register-options-url="/typo3/ajax/passkeys/manage/registration/options?token=test-token"', $result);
        self::assertStringContainsString('data-register-verify-url="/typo3/ajax/passkeys/manage/registration/verify?token=test-token"', $result);
        self::assertStringContainsString('data-rename-url="/typo3/ajax/passkeys/manage/rename?token=test-token"', $result);
        self::assertStringContainsString('data-remove-url="/typo3/ajax/passkeys/manage/remove?token=test-token"', $result);
    }

    #[Test]
    public function renderShowsPasskeyCountFromRepository(): void
    {
        $this->setUpBackendUser(42);
        $this->registerDependencies();

        $this->credentialRepository
            ->expects(self::once())
            ->method('countByBeUser')
            ->with(42)
            ->willReturn(5);

        $result = $this->subject->render([]);

        self::assertStringContainsString('id="passkey-count"', $result);
        self::assertStringContainsString('>5<', $result);
    }

    #[Test]
    public function renderShowsWarningBadgeWhenNoPasskeys(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $result = $this->subject->render([]);

        self::assertStringContainsString('badge-warning', $result);
    }

    #[Test]
    public function renderShowsInfoBadgeWhenSinglePasskey(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(1);

        $result = $this->subject->render([]);

        self::assertStringContainsString('badge-info', $result);
    }

    #[Test]
    public function renderShowsSuccessBadgeWhenMultiplePasskeys(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(2);

        $result = $this->subject->render([]);

        self::assertStringContainsString('badge-success', $result);
    }

    #[Test]
    public function renderIncludesTranslatedLabels(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $result = $this->subject->render([]);

        self::assertStringContainsString('Passkeys', $result);
        self::assertStringContainsString('Manage your registered passkeys', $result);
        self::assertStringContainsString('Add Passkey', $result);
        self::assertStringContainsString('Name', $result);
        self::assertStringContainsString('Created', $result);
        self::assertStringContainsString('Last Used', $result);
        self::assertStringContainsString('Actions', $result);
    }

    #[Test]
    public function renderIncludesPasskeyTableStructure(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $result = $this->subject->render([]);

        self::assertStringContainsString('id="passkey-list-table"', $result);
        self::assertStringContainsString('id="passkey-list-body"', $result);
        self::assertStringContainsString('id="passkey-add-btn"', $result);
        self::assertStringContainsString('id="passkey-single-warning"', $result);
        self::assertStringContainsString('id="passkey-empty"', $result);
    }

    #[Test]
    public function renderUsesFallbackWhenTranslationReturnsEmpty(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        // Override with a language service that returns empty strings
        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturn('');
        $GLOBALS['LANG'] = $languageService;

        $result = $this->subject->render([]);

        // Fallback labels should be used
        self::assertStringContainsString('Passkeys', $result);
        self::assertStringContainsString('Add Passkey', $result);
    }

    #[Test]
    public function renderEscapesHtmlInTranslations(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturnCallback(static function (string $key): string {
                if (\str_contains($key, 'manage.title')) {
                    return '<script>alert("xss")</script>';
                }

                return 'safe';
            });
        $GLOBALS['LANG'] = $languageService;

        $result = $this->subject->render([]);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function renderReturnsWarningWhenEncryptionKeyTooShort(): void
    {
        $this->setUpBackendUser(1);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'short';

        $result = $this->subject->render([]);

        self::assertStringContainsString('alert alert-danger', $result);
        self::assertStringContainsString('encryption key', $result);
        self::assertStringNotContainsString('passkey-management-container', $result);
    }

    #[Test]
    public function renderReturnsWarningWhenEncryptionKeyMissing(): void
    {
        $this->setUpBackendUser(1);

        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        $result = $this->subject->render([]);

        self::assertStringContainsString('alert alert-danger', $result);
        self::assertStringNotContainsString('passkey-management-container', $result);
    }

    #[Test]
    public function renderProceedsNormallyWhenEncryptionKeyValid(): void
    {
        $this->setUpBackendUser(1);
        $this->registerDependencies();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = \str_repeat('a', 32);

        $this->credentialRepository
            ->method('countByBeUser')
            ->willReturn(0);

        $result = $this->subject->render([]);

        self::assertStringContainsString('passkey-management-container', $result);
        self::assertStringNotContainsString('alert alert-danger', $result);
    }

    private function setUpBackendUser(int $uid): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid];
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function registerDependencies(): void
    {
        GeneralUtility::setSingletonInstance(PageRenderer::class, $this->pageRenderer);
        GeneralUtility::addInstance(CredentialRepository::class, $this->credentialRepository);
        GeneralUtility::setSingletonInstance(UriBuilder::class, $this->uriBuilder);
    }
}
