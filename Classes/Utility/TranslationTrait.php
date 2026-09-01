<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Utility;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Provides a translate() helper for extension locallang lookups with fallback.
 */
trait TranslationTrait
{
    /**
     * Translate a key from the extension's locallang file with a fallback.
     *
     * Uses $GLOBALS['LANG'] (LanguageService) which is available after the
     * authentication middleware has run.
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
