<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysBe\EventListener\InjectPasskeyBanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewInterface;

#[CoversClass(InjectPasskeyBanner::class)]
final class InjectPasskeyBannerTest extends TestCase
{
    #[Test]
    public function invokeLoadsPasskeyBannerJavaScriptModule(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer
            ->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@netresearch/nr-passkeys-be/PasskeyBanner.js');
        $view = $this->createMock(ViewInterface::class);
        $event = new AfterBackendPageRenderEvent('<html></html>', $view);
        $subject = new InjectPasskeyBanner($pageRenderer);
        $subject($event);
    }

    #[Test]
    public function invokeAddsInlineLanguageLabelFile(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer
            ->expects(self::once())
            ->method('addInlineLanguageLabelFile')
            ->with('EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf', 'js.');
        $view = $this->createMock(ViewInterface::class);
        $event = new AfterBackendPageRenderEvent('<html></html>', $view);
        $subject = new InjectPasskeyBanner($pageRenderer);
        $subject($event);
    }

    #[Test]
    public function invokeAddsThemeAwareCssFile(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer
            ->expects(self::once())
            ->method('addCssFile')
            ->with('EXT:nr_passkeys_be/Resources/Public/Css/backend.css');
        $view = $this->createMock(ViewInterface::class);
        $event = new AfterBackendPageRenderEvent('<html></html>', $view);
        $subject = new InjectPasskeyBanner($pageRenderer);
        $subject($event);
    }
}
