<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use Netresearch\NrPasskeysBe\Domain\Enum\EnforcementLevel;
use Netresearch\NrPasskeysBe\Service\AdoptionStatsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module controller for the passkey management admin module.
 *
 * Provides the dashboard and help views under Admin Tools > Passkey Management.
 */
final class AdminModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AdoptionStatsService $adoptionStatsService,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * Render the passkey adoption dashboard.
     */
    public function dashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Passkey Management');

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
                'editUrl' => $editUrl,
            ];
        }

        $moduleTemplate->assignMultiple([
            'totalUsers' => $stats->totalUsers,
            'usersWithPasskeys' => $stats->usersWithPasskeys,
            'adoptionPercentage' => $stats->adoptionPercentage(),
            'groups' => $groupData,
            'usersWithoutPasskeys' => $userData,
            'enforcementLevels' => $this->getEnforcementLevelOptions(),
        ]);

        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf',
            'js.',
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@netresearch/nr-passkeys-be/PasskeyDashboard.js',
        );

        return $moduleTemplate->renderResponse('AdminModule/Dashboard');
    }

    /**
     * Render the help/documentation view.
     */
    public function helpAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Passkey Management – Help');

        return $moduleTemplate->renderResponse('AdminModule/Help');
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
     * Translate a key from the extension's locallang file with a fallback.
     */
    private function translate(string $key, string $fallback): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $translated = $lang->sL('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:' . $key);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $fallback;
    }
}
