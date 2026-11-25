<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysBe\EventListener\InjectSudoModePasskey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;

#[CoversClass(InjectSudoModePasskey::class)]
final class InjectSudoModePasskeyTest extends TestCase
{
    #[Test]
    public function theModuleAndItsLabelsAreLoaded(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/SudoModePasskey.js');
        $pageRenderer
            ->expects(self::once())
            ->method('addInlineLanguageLabelFile')
            ->with('EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf', 'js.');

        (new InjectSudoModePasskey($pageRenderer))(
            new AfterBackendPageRenderEvent('<html></html>', $this->createMock(ViewInterface::class)),
        );
    }
}
