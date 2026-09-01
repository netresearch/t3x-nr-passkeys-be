<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the passkey adoption banner JS module on every backend page.
 *
 * Uses AfterBackendPageRenderEvent which fires after the view is rendered
 * but before PageRenderer::renderResponse(). Since PageRenderer is a
 * singleton, adding a JS module here is picked up by the final render.
 */
#[AsEventListener(identifier: 'nr-passkeys-be/inject-passkey-banner')]
final readonly class InjectPasskeyBanner
{
    public function __construct(private PageRenderer $pageRenderer) {}

    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf', 'js.');
        // Theme-aware banner styles (colors inherit from the core callout
        // component so the banner follows the v14 light/dark scheme).
        $this->pageRenderer->addCssFile('EXT:nr_passkeys_be/Resources/Public/Css/backend.css');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-passkeys-be/PasskeyBanner.js');
    }
}
