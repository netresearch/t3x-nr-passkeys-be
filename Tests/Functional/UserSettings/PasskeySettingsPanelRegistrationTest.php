<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Functional\UserSettings;

use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanel;
use Netresearch\NrPasskeysBe\UserSettings\PasskeySettingsPanelElement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression tests for the User Settings passkey panel FormEngine wiring.
 *
 * These guard the v0.9.1/v0.9.2 fix: on TYPO3 14 the `passkeys` user-settings
 * column must carry an explicit `renderType` (a bare `type=user` column made
 * SingleFieldContainer throw "Too few arguments" and logged a missing-renderType
 * warning). The unit tests for PasskeySettingsPanelElement mock all FormEngine
 * dependencies and therefore never exercise the registration that actually broke.
 *
 * @see PasskeySettingsPanelElement
 * @see PasskeySettingsPanel
 */
#[CoversNothing]
final class PasskeySettingsPanelRegistrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['setup'];

    protected array $testExtensionsToLoad = ['netresearch/nr-passkeys-be'];

    /**
     * The node must resolve to our element via the registered renderType.
     *
     * This is the load-bearing assertion: it proves ext_localconf.php registered
     * the `nrPasskeySettingsPanel` node and that the service is constructable via
     * DI (public: true). Independent of TYPO3 version, since the node registry
     * entry is registered unconditionally.
     */
    #[Test]
    public function nodeFactoryResolvesPasskeySettingsPanelRenderType(): void
    {
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);

        // create() instantiates and calls setData() but does not call render(),
        // so a minimal data array carrying only the renderType is sufficient.
        $node = $nodeFactory->create(['renderType' => 'nrPasskeySettingsPanel']);
        self::assertInstanceOf(PasskeySettingsPanelElement::class, $node);
    }

    /**
     * On TYPO3 14+ the passkeys column is registered through the TCA-based
     * user-settings API and must declare type=user WITH the renderType.
     * A bare type=user (the v0.9.0 state) is exactly what regressed.
     */
    #[Test]
    public function userSettingsColumnDeclaresRenderTypeOnTypo3V14(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TCA-based user settings column is TYPO3 v14+ only.');
        }

        $config = $GLOBALS['TCA']['be_users']['columns']['user_settings']['columns']['passkeys']['config'] ?? null;
        self::assertIsArray($config, 'passkeys user-settings column must be registered on v14+');
        self::assertSame('user', $config['type'] ?? null);
        self::assertSame(
            'nrPasskeySettingsPanel',
            $config['renderType'] ?? null,
            'type=user column must declare a renderType or SingleFieldContainer throws',
        );
    }

    /**
     * On TYPO3 12/13 the legacy userFunc path is used and no FormEngine
     * renderType is wired for the user-settings column.
     */
    #[Test]
    public function userSettingsColumnUsesUserFuncBelowTypo3V14(): void
    {
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::markTestSkipped('Legacy userFunc path applies to TYPO3 < 14 only.');
        }

        $column = $GLOBALS['TYPO3_USER_SETTINGS']['columns']['passkeys'] ?? null;
        self::assertIsArray($column, 'passkeys user-settings column must be registered on v12/v13');
        self::assertSame('user', $column['type'] ?? null);
        self::assertSame(PasskeySettingsPanel::class . '->render', $column['userFunc'] ?? null);
    }
}
