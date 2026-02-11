<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Form\Element;

use Netresearch\NrPasskeysBe\Domain\Model\Credential;
use Netresearch\NrPasskeysBe\Form\Element\PasskeyInfoElement;
use Netresearch\NrPasskeysBe\Service\CredentialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(PasskeyInfoElement::class)]
final class PasskeyInfoElementTest extends TestCase
{
    private CredentialRepository&MockObject $credentialRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // TYPO3 constant used by AbstractFormElement::wrapWithFieldsetAndLegend()
        if (!\defined('LF')) {
            \define('LF', "\n");
        }

        $this->credentialRepository = $this->createMock(CredentialRepository::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function renderReturnsEmptyForNonBeUsersTable(): void
    {
        $subject = $this->createSubject([
            'tableName' => 'fe_users',
            'databaseRow' => ['uid' => 42],
        ]);

        $result = $subject->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyForZeroUserId(): void
    {
        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 0],
        ]);

        $result = $subject->render();

        self::assertEmpty($result['html'] ?? '');
    }

    #[Test]
    public function renderShowsDisabledBadgeWhenNoCredentials(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $this->credentialRepository
            ->expects(self::once())
            ->method('findAllByBeUser')
            ->with(42)
            ->willReturn([]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('badge-danger', $html);
        self::assertStringContainsString('No passkeys', $html);
        self::assertStringNotContainsString('badge-success', $html);
    }

    #[Test]
    public function renderShowsEnabledBadgeWithActiveCredentials(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: 'My Key', createdAt: 1700000000);

        $this->credentialRepository
            ->expects(self::once())
            ->method('findAllByBeUser')
            ->with(42)
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('badge-success', $html);
        self::assertStringContainsString('1 Enabled', $html);
        self::assertStringContainsString('My Key', $html);
        self::assertStringContainsString('Active', $html);
    }

    #[Test]
    public function renderShowsRevokedCredentialWithRevokedBadge(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(
            uid: 10,
            beUser: 42,
            label: 'Old Key',
            createdAt: 1700000000,
            revokedAt: 1700001000,
            revokedBy: 1,
        );

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('Old Key', $html);
        self::assertStringContainsString('Revoked', $html);
        // No revoke button for already-revoked credential
        self::assertStringNotContainsString('data-credential-uid="10"', $html);
    }

    #[Test]
    public function renderShowsRevokeButtonsForAdminWithActiveCredentials(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: 'Active Key', createdAt: 1700000000);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('t3js-passkey-revoke-button', $html);
        self::assertStringContainsString('data-credential-uid="10"', $html);
        self::assertStringContainsString('t3js-passkey-revoke-all-button', $html);
        self::assertStringContainsString('t3js-passkey-unlock-button', $html);
    }

    #[Test]
    public function renderHidesManagementButtonsForNonAdmin(): void
    {
        $this->setUpNonAdminUser(42);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: 'Key', createdAt: 1700000000);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringNotContainsString('t3js-passkey-revoke-button', $html);
        self::assertStringNotContainsString('t3js-passkey-revoke-all-button', $html);
        self::assertStringNotContainsString('t3js-passkey-unlock-button', $html);
        self::assertEmpty($result['javaScriptModules'] ?? []);
    }

    #[Test]
    public function renderLoadsJavaScriptModuleForAdmin(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();

        self::assertNotEmpty($result['javaScriptModules']);
        $jsModule = $result['javaScriptModules'][0];
        self::assertStringContainsString('PasskeyAdminInfo.js', $jsModule->getName());
    }

    #[Test]
    public function renderShowsMultipleCredentials(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred1 = new Credential(uid: 10, beUser: 42, label: 'MacBook', createdAt: 1700000000, lastUsedAt: 1700001000);
        $cred2 = new Credential(uid: 11, beUser: 42, label: 'YubiKey', createdAt: 1700002000);
        $cred3 = new Credential(uid: 12, beUser: 42, label: 'Old Key', createdAt: 1699000000, revokedAt: 1700003000, revokedBy: 1);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred1, $cred2, $cred3]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('2 Enabled', $html);
        self::assertStringContainsString('MacBook', $html);
        self::assertStringContainsString('YubiKey', $html);
        self::assertStringContainsString('Old Key', $html);
        // Revoke buttons for active credentials only
        self::assertStringContainsString('data-credential-uid="10"', $html);
        self::assertStringContainsString('data-credential-uid="11"', $html);
        self::assertStringNotContainsString('data-credential-uid="12"', $html);
    }

    #[Test]
    public function renderDisablesRevokeAllButtonWhenNoActiveCredentials(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: 'Revoked', createdAt: 1700000000, revokedAt: 1700001000, revokedBy: 1);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('t3js-passkey-revoke-all-button', $html);
        self::assertStringContainsString('disabled', $html);
    }

    #[Test]
    public function renderShowsNeverLabelForUnusedCredential(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: 'New Key', createdAt: 1700000000, lastUsedAt: 0);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('Never', $html);
    }

    #[Test]
    public function renderUsesDefaultLabelForCredentialWithoutLabel(): void
    {
        $this->setUpAdminUser(1);
        $this->setUpLanguageService();

        $cred = new Credential(uid: 10, beUser: 42, label: '', createdAt: 1700000000);

        $this->credentialRepository
            ->method('findAllByBeUser')
            ->willReturn([$cred]);

        $subject = $this->createSubject([
            'tableName' => 'be_users',
            'databaseRow' => ['uid' => 42, 'username' => 'testuser'],
            'parameterArray' => ['fieldConf' => ['label' => 'Passkeys']],
        ]);

        $result = $subject->render();
        $html = $result['html'];

        self::assertStringContainsString('Passkey #10', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSubject(array $data): PasskeyInfoElement
    {
        $subject = new PasskeyInfoElement($this->credentialRepository);
        $subject->setData($data);
        return $subject;
    }

    private function setUpAdminUser(int $uid): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'admin' => 1];
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('isSystemMaintainer')->willReturn(true);
        $backendUser->method('shallDisplayDebugInformation')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function setUpNonAdminUser(int $uid): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid, 'admin' => 0];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('isSystemMaintainer')->willReturn(false);
        $backendUser->method('shallDisplayDebugInformation')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function setUpLanguageService(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static function (string $key): string {
                $map = [
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.enabled' => 'Enabled',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.disabled' => 'No passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke' => 'Revoke',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll' => 'Revoke all passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll.confirm.title' => 'Revoke all passkeys',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revokeAll.confirm.text' => 'Are you sure?',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke.confirm.title' => 'Revoke passkey',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.revoke.confirm.text' => 'Are you sure you want to revoke this passkey?',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock' => 'Unlock account',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock.confirm.title' => 'Unlock account',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.unlock.confirm.text' => 'Reset the rate limiter?',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.status.active' => 'Active',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.status.revoked' => 'Revoked',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.created' => 'Created',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.lastUsed' => 'Last used',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.passkeys.never' => 'Never',
                    'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:admin.cancel' => 'Cancel',
                ];
                return $map[$key] ?? $key;
            },
        );
        $GLOBALS['LANG'] = $languageService;
    }
}
