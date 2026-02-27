<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Controller\AdminModuleController;
use Netresearch\NrPasskeysBe\Domain\Dto\AdoptionStats;
use Netresearch\NrPasskeysBe\Domain\Dto\GroupEnforcementInfo;
use Netresearch\NrPasskeysBe\Domain\Dto\UserPasskeyStatus;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Page\PageRenderer;

#[CoversClass(AdminModuleController::class)]
final class AdminModuleControllerTest extends TestCase
{
    private AdminModuleController $subject;

    private ModuleTemplateFactory&MockObject $moduleTemplateFactory;

    private AdoptionStatsService&MockObject $adoptionStatsService;

    private PageRenderer&MockObject $pageRenderer;

    private UriBuilder&MockObject $uriBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleTemplateFactory = $this->createMock(ModuleTemplateFactory::class);
        $this->adoptionStatsService = $this->createMock(AdoptionStatsService::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn('/typo3/record/edit?mocked=1');

        $this->subject = new AdminModuleController(
            $this->moduleTemplateFactory,
            $this->adoptionStatsService,
            $this->pageRenderer,
            $this->uriBuilder,
        );
    }

    #[Test]
    public function dashboardActionPassesStatsToView(): void
    {
        $stats = new AdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 6,
            groups: [],
            usersWithoutPasskeys: [],
        );

        $this->adoptionStatsService
            ->expects(self::once())
            ->method('getStats')
            ->willReturn($stats);

        $moduleTemplate = $this->createMock(ModuleTemplate::class);
        $moduleTemplate->expects(self::once())
            ->method('setTitle')
            ->with('Passkey Management');

        $moduleTemplate->expects(self::once())
            ->method('assignMultiple')
            ->with(self::callback(static function (array $variables): bool {
                return $variables['totalUsers'] === 10
                    && $variables['usersWithPasskeys'] === 6
                    && $variables['adoptionPercentage'] === 60.0
                    && $variables['groups'] === []
                    && $variables['usersWithoutPasskeys'] === []
                    && \is_array($variables['enforcementLevels'])
                    && isset($variables['enforcementLevels']['off'])
                    && isset($variables['enforcementLevels']['encourage'])
                    && isset($variables['enforcementLevels']['required'])
                    && isset($variables['enforcementLevels']['enforced']);
            }));

        $expectedResponse = new HtmlResponse('<html></html>');
        $moduleTemplate->expects(self::once())
            ->method('renderResponse')
            ->with('AdminModule/Dashboard')
            ->willReturn($expectedResponse);

        $this->moduleTemplateFactory
            ->expects(self::once())
            ->method('create')
            ->willReturn($moduleTemplate);

        $this->pageRenderer
            ->expects(self::once())
            ->method('addInlineLanguageLabelFile')
            ->with(
                'EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf',
                'js.',
            );
        $this->pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyDashboard.js');

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

        $stats = new AdoptionStats(
            totalUsers: 10,
            usersWithPasskeys: 6,
            groups: [$group],
            usersWithoutPasskeys: [$user],
        );

        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);

        $capturedVariables = [];
        $moduleTemplate = $this->createMock(ModuleTemplate::class);
        $moduleTemplate->method('setTitle');
        $moduleTemplate->method('assignMultiple')
            ->willReturnCallback(static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                $capturedVariables = $vars;
                return $moduleTemplate;
            });
        $moduleTemplate->method('renderResponse')
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
        self::assertArrayHasKey('editUrl', $userData);
        self::assertIsString($userData['editUrl']);
    }

    #[Test]
    public function helpActionRendersHelpTemplate(): void
    {
        $moduleTemplate = $this->createMock(ModuleTemplate::class);
        $moduleTemplate->expects(self::once())
            ->method('setTitle')
            ->with('Passkey Management – Help');

        $expectedResponse = new HtmlResponse('<html></html>');
        $moduleTemplate->expects(self::once())
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
        $stats = new AdoptionStats(
            totalUsers: 0,
            usersWithPasskeys: 0,
            groups: [],
            usersWithoutPasskeys: [],
        );

        $this->adoptionStatsService
            ->method('getStats')
            ->willReturn($stats);

        $capturedVariables = [];
        $moduleTemplate = $this->createMock(ModuleTemplate::class);
        $moduleTemplate->method('setTitle');
        $moduleTemplate->method('assignMultiple')
            ->willReturnCallback(static function (array $vars) use (&$capturedVariables, $moduleTemplate): ModuleTemplate {
                $capturedVariables = $vars;
                return $moduleTemplate;
            });
        $moduleTemplate->method('renderResponse')
            ->willReturn(new HtmlResponse('<html></html>'));

        $this->moduleTemplateFactory
            ->method('create')
            ->willReturn($moduleTemplate);

        $request = $this->createMock(ServerRequestInterface::class);
        $this->subject->dashboardAction($request);

        self::assertArrayHasKey('enforcementLevels', $capturedVariables);
        $levels = $capturedVariables['enforcementLevels'];
        self::assertSame([
            'off' => 'Off',
            'encourage' => 'Encourage',
            'required' => 'Required',
            'enforced' => 'Enforced',
        ], $levels);
    }
}
