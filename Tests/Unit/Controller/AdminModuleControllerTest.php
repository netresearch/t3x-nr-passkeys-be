<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Configuration\ExtensionConfiguration as ExtensionConfigurationVO;
use Netresearch\NrPasskeysBe\Controller\AdminModuleController;
use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\DocHeaderComponent;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\Components\Menu\MenuItem;
use TYPO3\CMS\Backend\Template\Components\MenuRegistry;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(AdminModuleController::class)]
final class AdminModuleControllerTest extends TestCase
{
    private AdminModuleController $subject;

    private ModuleTemplateFactory&MockObject $moduleTemplateFactory;

    private AdoptionStatsService&MockObject $adoptionStatsService;

    private ExtensionConfigurationService&MockObject $configService;

    private IconFactory&MockObject $iconFactory;

    private PageRenderer&MockObject $pageRenderer;

    private UriBuilder&MockObject $uriBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleTemplateFactory = $this->createMock(ModuleTemplateFactory::class);
        $this->adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $this->configService = $this->createMock(ExtensionConfigurationService::class);
        $this->configService
            ->method('getConfiguration')
            ->willReturn(new ExtensionConfigurationVO());
        $this->configService
            ->method('getEffectiveRpId')
            ->willReturn('localhost');
        $this->iconFactory = $this->createMock(IconFactory::class);
        $this->iconFactory
            ->method('getIcon')
            ->willReturn($this->createMock(Icon::class));
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder
            ->method('buildUriFromRoute')
            ->willReturn('/typo3/record/edit?mocked=1');
        $this->subject = new AdminModuleController(
            $this->moduleTemplateFactory,
            $this->adoptionStatsService,
            $this->configService,
            $this->iconFactory,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    /**
     * Create a ModuleTemplate mock with DocHeader menu chain set up.
     */
    private function createModuleTemplateMock(): ModuleTemplate&MockObject
    {
        $menuItem = $this->createMock(MenuItem::class);
        $menuItem
            ->method('setTitle')
            ->willReturnSelf();
        $menuItem
            ->method('setHref')
            ->willReturnSelf();
        $menuItem
            ->method('setActive')
            ->willReturnSelf();
        $menu = $this->createMock(Menu::class);
        $menu
            ->method('setIdentifier')
            ->willReturnSelf();
        $menu
            ->method('makeMenuItem')
            ->willReturn($menuItem);
        $menuRegistry = $this->createMock(MenuRegistry::class);
        $menuRegistry
            ->method('makeMenu')
            ->willReturn($menu);
        $linkButton = $this->createMock(LinkButton::class);
        $linkButton
            ->method('setHref')
            ->willReturnSelf();
        $linkButton
            ->method('setTitle')
            ->willReturnSelf();
        $linkButton
            ->method('setIcon')
            ->willReturnSelf();
        $linkButton
            ->method('setShowLabelText')
            ->willReturnSelf();
        $buttonBar = $this->createMock(ButtonBar::class);
        $buttonBar
            ->method('makeLinkButton')
            ->willReturn($linkButton);

        // TYPO3 v14+: the controller resolves the docheader components via
        // ComponentFactory (GeneralUtility::makeInstance). Queue a mock returning the
        // same component doubles. On v12/v13 the class is absent and the make* mocks
        // above are used instead.
        if (\class_exists(ComponentFactory::class)) {
            $componentFactory = $this->createMock(ComponentFactory::class);
            $componentFactory
                ->method('createMenu')
                ->willReturn($menu);
            $componentFactory
                ->method('createMenuItem')
                ->willReturn($menuItem);
            $componentFactory
                ->method('createLinkButton')
                ->willReturn($linkButton);
            GeneralUtility::addInstance(ComponentFactory::class, $componentFactory);
        }

        $docHeader = $this->createMock(DocHeaderComponent::class);
        $docHeader
            ->method('getMenuRegistry')
            ->willReturn($menuRegistry);
        $docHeader
            ->method('getButtonBar')
            ->willReturn($buttonBar);
        $moduleTemplate = $this->createMock(ModuleTemplate::class);
        $moduleTemplate
            ->method('getDocHeaderComponent')
            ->willReturn($docHeader);

        return $moduleTemplate;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function dashboardActionPassesStatsToView(): void
    {
        $stats = new AdoptionStats(totalUsers: 10, usersWithPasskeys: 6, groups: [], usersWithoutPasskeys: []);
        $this->adoptionStatsService
            ->expects(self::once())
            ->method('getStats')
            ->willReturn($stats);
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate
            ->expects(self::once())
            ->method('setTitle')
            ->with('Passkey Management');
        $moduleTemplate
            ->expects(self::once())
            ->method('assignMultiple')
            ->with(
                self::callback(
                    static fn(array $variables): bool => $variables['totalUsers'] === 10 && $variables['usersWithPasskeys'] === 6 && $variables['adoptionPercentage'] === 60.0 && $variables['groups'] === [] && $variables['usersWithoutPasskeys'] === [] && \is_array($variables['enforcementLevels']) && isset($variables['enforcementLevels']['off']) && isset($variables['enforcementLevels']['encourage']) && isset($variables['enforcementLevels']['required']) && isset($variables['enforcementLevels']['enforced']) && \array_key_exists('helpUrl', $variables) && \array_key_exists('configRpId', $variables) && \array_key_exists('isNewInstallation', $variables),
                ),
            );
        $expectedResponse = new HtmlResponse('<html></html>');
        $moduleTemplate
            ->expects(
                self::once(),
            )
            ->method('renderResponse')
            ->with('AdminModule/Dashboard')
            ->willReturn($expectedResponse);
        $this->moduleTemplateFactory
            ->expects(self::once())
            ->method('create')
            ->willReturn($moduleTemplate);
        $this->pageRenderer
            ->expects(
                self::once(),
            )
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyDashboard.js');
        $this->pageRenderer
            ->expects(
                self::once(),
            )
            ->method('addInlineLanguageLabelFile')
            ->with('EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf', 'js.');
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->subject->dashboardAction($request);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function dashboardActionFlattensGroupData(): void
    {
        $group = new GroupEnforcementInfo(
            uid: 5,
            title: 'Editors',
            enforcement: 'encourage',
            gracePeriodDays: 14,
            totalUsers: 8,
            usersWithPasskeys: 3,
        );
        $user = new UserPasskeyStatus(
            uid: 42,
            username: 'editor',
            realName: 'Test Editor',
            groups: '5',
            gracePeriodStart: 1700000000,
            gracePeriodRemainingDays: 7,
        );
        $stats = new AdoptionStats(totalUsers: 10, usersWithPasskeys: 6, groups: [$group], usersWithoutPasskeys: [$user]);
        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);
        $capturedVariables = [];
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate->method('setTitle');
        $moduleTemplate
            ->method('assignMultiple')
            ->willReturnCallback(
                static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                    $capturedVariables = $vars;

                    return $moduleTemplate;
                },
            );
        $moduleTemplate
            ->method('renderResponse')
            ->willReturn(new HtmlResponse('<html></html>'));
        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($moduleTemplate);
        $request = $this->createMock(ServerRequestInterface::class);
        $this->subject->dashboardAction($request);

        // Verify group data is flattened to arrays
        self::assertCount(1, $capturedVariables['groups']);
        $groupData = $capturedVariables['groups'][0];
        self::assertSame(5, $groupData['uid']);
        self::assertSame('Editors', $groupData['title']);
        self::assertSame('encourage', $groupData['enforcement']);
        self::assertSame(14, $groupData['gracePeriodDays']);
        self::assertSame(8, $groupData['totalUsers']);
        self::assertSame(3, $groupData['usersWithPasskeys']);
        self::assertSame(37.5, $groupData['adoptionPercentage']);

        // Verify user data is flattened to arrays
        self::assertCount(1, $capturedVariables['usersWithoutPasskeys']);
        $userData = $capturedVariables['usersWithoutPasskeys'][0];
        self::assertSame(42, $userData['uid']);
        self::assertSame('editor', $userData['username']);
        self::assertSame('Test Editor', $userData['realName']);
        self::assertSame('5', $userData['groups']);
        self::assertSame(1700000000, $userData['gracePeriodStart']);
        self::assertSame(7, $userData['gracePeriodRemainingDays']);
        self::assertSame(0, $userData['nudgeUntil']);
        self::assertFalse($userData['hasActiveNudge']);
        self::assertArrayHasKey('editUrl', $userData);
        self::assertIsString($userData['editUrl']);
    }

    #[Test]
    public function helpActionRendersHelpTemplate(): void
    {
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate
            ->expects(self::once())
            ->method('setTitle')
            ->with('Passkey Management – Help');
        $moduleTemplate
            ->expects(
                self::once(),
            )
            ->method('assignMultiple')
            ->with(self::callback(static fn(array $vars): bool => \array_key_exists('dashboardUrl', $vars)))
            ->willReturnSelf();
        $expectedResponse = new HtmlResponse('<html></html>');
        $moduleTemplate
            ->expects(
                self::once(),
            )
            ->method('renderResponse')
            ->with('AdminModule/Help')
            ->willReturn($expectedResponse);
        $this->moduleTemplateFactory
            ->expects(self::once())
            ->method('create')
            ->willReturn($moduleTemplate);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->subject->helpAction($request);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function dashboardActionIncludesAllEnforcementLevels(): void
    {
        $stats = new AdoptionStats(totalUsers: 0, usersWithPasskeys: 0, groups: [], usersWithoutPasskeys: []);
        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);
        $capturedVariables = [];
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate->method('setTitle');
        $moduleTemplate
            ->method('assignMultiple')
            ->willReturnCallback(
                static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                    $capturedVariables = $vars;

                    return $moduleTemplate;
                },
            );
        $moduleTemplate
            ->method('renderResponse')
            ->willReturn(new HtmlResponse('<html></html>'));
        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($moduleTemplate);
        $request = $this->createMock(ServerRequestInterface::class);
        $this->subject->dashboardAction($request);
        self::assertArrayHasKey('enforcementLevels', $capturedVariables);
        $levels = $capturedVariables['enforcementLevels'];
        self::assertSame(
            ['off' => 'Off', 'encourage' => 'Encourage', 'required' => 'Required', 'enforced' => 'Enforced'],
            $levels,
        );
    }

    #[Test]
    public function dashboardActionUsesTranslatedEnforcementLevels(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->method('sL')
            ->willReturnCallback(
                static function (string $key): string {
                    $map = [
                        'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:enforcement.level.off' => 'Aus',
                        'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:enforcement.level.encourage' => 'Empfehlen',
                        'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:enforcement.level.required' => 'Erforderlich',
                        'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:enforcement.level.enforced' => 'Erzwungen',
                    ];

                    return $map[$key] ?? '';
                },
            );
        $GLOBALS['LANG'] = $languageService;
        $stats = new AdoptionStats(totalUsers: 0, usersWithPasskeys: 0, groups: [], usersWithoutPasskeys: []);
        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);
        $capturedVariables = [];
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate->method('setTitle');
        $moduleTemplate
            ->method('assignMultiple')
            ->willReturnCallback(
                static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                    $capturedVariables = $vars;

                    return $moduleTemplate;
                },
            );
        $moduleTemplate
            ->method('renderResponse')
            ->willReturn(new HtmlResponse('<html></html>'));
        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($moduleTemplate);
        $request = $this->createMock(ServerRequestInterface::class);
        $this->subject->dashboardAction($request);
        self::assertArrayHasKey('enforcementLevels', $capturedVariables);
        $levels = $capturedVariables['enforcementLevels'];
        self::assertSame(
            ['off' => 'Aus', 'encourage' => 'Empfehlen', 'required' => 'Erforderlich', 'enforced' => 'Erzwungen'],
            $levels,
        );
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function adoptionBadgeTierProvider(): array
    {
        return [
            'no users' => [0, 0, 'No users'],
            'getting started (0%)' => [10, 0, 'Getting started'],
            'getting started (20%)' => [10, 2, 'Getting started'],
            'bronze (25%)' => [4, 1, 'Bronze'],
            'bronze (49%)' => [100, 49, 'Bronze'],
            'silver (50%)' => [10, 5, 'Silver'],
            'silver (74%)' => [100, 74, 'Silver'],
            'gold (75%)' => [4, 3, 'Gold'],
            'gold (99%)' => [100, 99, 'Gold'],
            'platinum (100%)' => [5, 5, 'Platinum'],
        ];
    }

    #[Test]
    #[DataProvider('adoptionBadgeTierProvider')]
    public function dashboardActionAssignsCorrectAdoptionBadge(int $totalUsers, int $withPasskeys, string $expectedLabel): void
    {
        $stats = new AdoptionStats(
            totalUsers: $totalUsers,
            usersWithPasskeys: $withPasskeys,
            groups: [],
            usersWithoutPasskeys: [],
        );
        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);
        $capturedVariables = [];
        $moduleTemplate = $this->createModuleTemplateMock();
        $moduleTemplate->method('setTitle');
        $moduleTemplate
            ->method('assignMultiple')
            ->willReturnCallback(
                static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                    $capturedVariables = $vars;

                    return $moduleTemplate;
                },
            );
        $moduleTemplate
            ->method('renderResponse')
            ->willReturn(new HtmlResponse('<html></html>'));
        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($moduleTemplate);
        $request = $this->createMock(ServerRequestInterface::class);
        $this->subject->dashboardAction($request);
        self::assertArrayHasKey('adoptionBadge', $capturedVariables);
        $badge = $capturedVariables['adoptionBadge'];
        self::assertIsArray($badge);
        self::assertSame($expectedLabel, $badge['label']);
        self::assertArrayHasKey('class', $badge);
        self::assertArrayHasKey('icon', $badge);
    }
}
