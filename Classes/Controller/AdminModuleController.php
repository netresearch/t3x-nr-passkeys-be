<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Netresearch\NrPasskeysBe\Service\ExtensionConfigurationService;
use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\Components\Menu\MenuItem;
use TYPO3\CMS\Backend\Template\Components\MenuRegistry;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module controller for the passkey management admin module.
 *
 * Provides the dashboard and help views under Admin Tools > Passkey Management.
 */
final class AdminModuleController
{
    use TranslationTrait;

    /**
     * Resolved docheader ComponentFactory (TYPO3 v14+), or null on v12/v13 where it
     * does not exist. false means "not yet resolved" (lazy, resolved at most once).
     */
    private ComponentFactory|false|null $componentFactory = false;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AdoptionStatsService $adoptionStatsService,
        private readonly ExtensionConfigurationService $configService,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * Render the passkey adoption dashboard.
     */
    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.title', 'Passkey Management'));
        $this->buildDocHeaderMenu($moduleTemplate, 'dashboard');
        $this->addHelpButton($moduleTemplate);

        $stats = $this->adoptionStatsService->getStats();

        $groupData = [];
        foreach ($stats->groups as $group) {
            $groupData[] = [
                'uid' => $group->uid,
                'title' => $group->title,
                'enforcement' => $group->enforcement,
                'gracePeriodDays' => $group->gracePeriodDays,
                'totalUsers' => $group->totalUsers,
                'usersWithPasskeys' => $group->usersWithPasskeys,
                'adoptionPercentage' => $group->adoptionPercentage(),
            ];
        }

        $userData = [];
        foreach ($stats->usersWithoutPasskeys as $user) {
            $editUrl = (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit[be_users][' . $user->uid . ']' => 'edit',
            ]);

            $userData[] = [
                'uid' => $user->uid,
                'username' => $user->username,
                'realName' => $user->realName,
                'groups' => $user->groups,
                'gracePeriodStart' => $user->gracePeriodStart,
                'gracePeriodRemainingDays' => $user->gracePeriodRemainingDays,
                'nudgeUntil' => $user->nudgeUntil,
                'hasActiveNudge' => $user->hasActiveNudge(),
                'editUrl' => $editUrl,
            ];
        }

        $config = $this->configService->getConfiguration();
        $adoptionPercentage = $stats->adoptionPercentage();

        $moduleTemplate->assignMultiple([
            'totalUsers' => $stats->totalUsers,
            'usersWithPasskeys' => $stats->usersWithPasskeys,
            'adoptionPercentage' => $adoptionPercentage,
            'adoptionBadge' => $this->adoptionBadge($adoptionPercentage, $stats->totalUsers),
            'groups' => $groupData,
            'usersWithoutPasskeys' => $userData,
            'usersWithoutPasskeysTruncated' => $stats->usersWithoutPasskeysTruncated,
            'usersWithoutPasskeysLimit' => AdoptionStatsService::USERS_WITHOUT_PASSKEYS_LIMIT,
            'enforcementLevels' => $this->getEnforcementLevelOptions(),
            'helpUrl' => (string) $this->uriBuilder->buildUriFromRoute('admin_passkeys.help'),
            'configRpId' => $this->configService->getEffectiveRpId(),
            'configRpIdIsAutoDetected' => $config->getRpId() === '',
            'configOriginIsAutoDetected' => $config->getOrigin() === '',
            'isNewInstallation' => $stats->usersWithPasskeys === 0,
        ]);

        $this->pageRenderer->loadJavaScriptModule(
            '@netresearch/nr-passkeys-be/PasskeyDashboard.js',
        );
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf',
            'js.',
        );

        return $moduleTemplate->renderResponse('AdminModule/Dashboard');
    }

    /**
     * Render the help/documentation view.
     */
    public function helpAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.title', 'Passkey Management') . ' – ' . $this->translate('module.help', 'Help'));
        $this->buildDocHeaderMenu($moduleTemplate, 'help');
        $this->addHelpButton($moduleTemplate);

        $moduleTemplate->assignMultiple([
            'dashboardUrl' => (string) $this->uriBuilder->buildUriFromRoute('admin_passkeys'),
        ]);

        return $moduleTemplate->renderResponse('AdminModule/Help');
    }

    /**
     * Set up the docheader tab menu for Dashboard/Help navigation.
     */
    private function buildDocHeaderMenu(ModuleTemplate $moduleTemplate, string $activeTab): void
    {
        $menuRegistry = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry();
        $menu = $this->createMenu($menuRegistry);
        $menu->setIdentifier('PasskeyManagementMenu');

        $dashboardItem = $this->createMenuItem($menu)
            ->setTitle($this->translate('module.dashboard', 'Dashboard'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys'));
        if ($activeTab === 'dashboard') {
            $dashboardItem->setActive(true);
        }
        $menu->addMenuItem($dashboardItem);

        $helpItem = $this->createMenuItem($menu)
            ->setTitle($this->translate('module.help', 'Help'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys.help'));
        if ($activeTab === 'help') {
            $helpItem->setActive(true);
        }
        $menu->addMenuItem($helpItem);

        $menuRegistry->addMenu($menu);
    }

    /**
     * Create a docheader Menu, using the v14+ ComponentFactory when available and
     * falling back to the (v12/v13-only, non-deprecated there) MenuRegistry::makeMenu().
     */
    private function createMenu(MenuRegistry $menuRegistry): Menu
    {
        $factory = $this->componentFactory();
        if ($factory !== null) {
            return $factory->createMenu();
        }

        return $menuRegistry->makeMenu();
    }

    /**
     * Create a docheader MenuItem, using the v14+ ComponentFactory when available and
     * falling back to the (v12/v13-only, non-deprecated there) Menu::makeMenuItem().
     */
    private function createMenuItem(Menu $menu): MenuItem
    {
        $factory = $this->componentFactory();
        if ($factory !== null) {
            return $factory->createMenuItem();
        }

        return $menu->makeMenuItem();
    }

    /**
     * Resolve the docheader ComponentFactory once. Returns null on TYPO3 v12/v13
     * where the class does not exist (callers fall back to the deprecated make* API).
     */
    private function componentFactory(): ?ComponentFactory
    {
        if ($this->componentFactory === false) {
            $this->componentFactory = \class_exists(ComponentFactory::class)
                ? GeneralUtility::makeInstance(ComponentFactory::class)
                : null;
        }

        return $this->componentFactory;
    }

    /**
     * Build an associative array of enforcement-level values to display labels.
     *
     * Uses LanguageService for i18n when available, falls back to English.
     *
     * @return array<string, string>
     */
    private function getEnforcementLevelOptions(): array
    {
        $options = [];
        foreach (EnforcementLevel::cases() as $level) {
            $fallback = match ($level) {
                EnforcementLevel::Off => 'Off',
                EnforcementLevel::Encourage => 'Encourage',
                EnforcementLevel::Required => 'Required',
                EnforcementLevel::Enforced => 'Enforced',
            };
            $options[$level->value] = $this->translate('enforcement.level.' . $level->value, $fallback);
        }

        return $options;
    }

    /**
     * Add a help icon button to the right side of the DocHeader button bar.
     */
    private function addHelpButton(ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $helpButton = $this->createLinkButton($buttonBar)
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys.help'))
            ->setTitle($this->translate('module.help', 'Help'))
            ->setIcon($this->iconFactory->getIcon(
                'actions-question-circle',
                ...(\enum_exists(IconSize::class) ? [IconSize::SMALL] : ['small']),
            ))
            ->setShowLabelText(false);
        $buttonBar->addButton($helpButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    /**
     * Create a docheader LinkButton, using the v14+ ComponentFactory when available and
     * falling back to the (v12/v13-only, non-deprecated there) ButtonBar::makeLinkButton().
     */
    private function createLinkButton(ButtonBar $buttonBar): LinkButton
    {
        $factory = $this->componentFactory();
        if ($factory !== null) {
            return $factory->createLinkButton();
        }

        return $buttonBar->makeLinkButton();
    }

    /**
     * Determine the adoption badge tier based on percentage.
     *
     * @return array{label: string, class: string, icon: string}
     */
    private function adoptionBadge(float $percentage, int $totalUsers): array
    {
        if ($totalUsers === 0) {
            return ['label' => $this->translate('dashboard.badge.noUsers', 'No users'), 'class' => 'badge-secondary', 'icon' => 'actions-minus'];
        }

        return match (true) {
            $percentage >= 100.0 => ['label' => $this->translate('dashboard.badge.platinum', 'Platinum'), 'class' => 'badge-success', 'icon' => 'actions-bolt'],
            $percentage >= 75.0 => ['label' => $this->translate('dashboard.badge.gold', 'Gold'), 'class' => 'badge-info', 'icon' => 'actions-star'],
            $percentage >= 50.0 => ['label' => $this->translate('dashboard.badge.silver', 'Silver'), 'class' => 'badge-secondary', 'icon' => 'actions-check'],
            $percentage >= 25.0 => ['label' => $this->translate('dashboard.badge.bronze', 'Bronze'), 'class' => 'badge-warning', 'icon' => 'actions-arrow-up'],
            default => ['label' => $this->translate('dashboard.badge.gettingStarted', 'Getting started'), 'class' => 'badge-danger', 'icon' => 'actions-rocket'],
        };
    }
}
