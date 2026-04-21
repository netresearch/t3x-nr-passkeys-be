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
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module controller for the passkey management admin module.
 *
 * Provides the dashboard and help views under Admin Tools > Passkey Management.
 */
final class AdminModuleController
{
    use TranslationTrait;
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
            'adoptionBadge' => self::adoptionBadge($adoptionPercentage, $stats->totalUsers),
            'groups' => $groupData,
            'usersWithoutPasskeys' => $userData,
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
        $menu = $menuRegistry->makeMenu();
        $menu->setIdentifier('PasskeyManagementMenu');

        $dashboardItem = $menu->makeMenuItem()
            ->setTitle($this->translate('module.dashboard', 'Dashboard'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys'));
        if ($activeTab === 'dashboard') {
            $dashboardItem->setActive(true);
        }
        $menu->addMenuItem($dashboardItem);

        $helpItem = $menu->makeMenuItem()
            ->setTitle($this->translate('module.help', 'Help'))
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys.help'));
        if ($activeTab === 'help') {
            $helpItem->setActive(true);
        }
        $menu->addMenuItem($helpItem);

        $menuRegistry->addMenu($menu);
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
        $helpButton = $buttonBar->makeLinkButton()
            ->setHref((string) $this->uriBuilder->buildUriFromRoute('admin_passkeys.help'))
            ->setTitle($this->translate('module.help', 'Help'))
            ->setIcon($this->iconFactory->getIcon(
                'actions-question-circle',
                // v12: IconSize doesn't exist, getIcon() accepts string 'small'
                // v13+: IconSize enum required
                // @phpstan-ignore argument.type
                \enum_exists(IconSize::class) ? IconSize::SMALL : 'small',
            ))
            ->setShowLabelText(false);
        $buttonBar->addButton($helpButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
    }

    /**
     * Determine the adoption badge tier based on percentage.
     *
     * @return array{label: string, class: string, icon: string}
     */
    private static function adoptionBadge(float $percentage, int $totalUsers): array
    {
        if ($totalUsers === 0) {
            return ['label' => 'No users', 'class' => 'badge-secondary', 'icon' => 'actions-minus'];
        }

        return match (true) {
            $percentage >= 100.0 => ['label' => 'Platinum', 'class' => 'badge-success', 'icon' => 'actions-bolt'],
            $percentage >= 75.0 => ['label' => 'Gold', 'class' => 'badge-info', 'icon' => 'actions-star'],
            $percentage >= 50.0 => ['label' => 'Silver', 'class' => 'badge-secondary', 'icon' => 'actions-check'],
            $percentage >= 25.0 => ['label' => 'Bronze', 'class' => 'badge-warning', 'icon' => 'actions-arrow-up'],
            default => ['label' => 'Getting started', 'class' => 'badge-danger', 'icon' => 'actions-rocket'],
        };
    }
}
