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
 * Loads the passkey button for TYPO3's sudo-mode dialog.
 *
 * The dialog is a modal in the top backend document, also when a module in
 * the content frame asks for it, so the top document is where the script has
 * to run.
 */
#[AsEventListener(identifier: 'nr-passkeys-be/inject-sudo-mode-passkey')]
final readonly class InjectSudoModePasskey
{
    public function __construct(
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf',
            'js.',
        );
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-passkeys-be/SudoModePasskey.js');
    }
}
