<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use Netresearch\NrPasskeysBe\Utility\TranslationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(TranslationTrait::class)]
final class TranslationTraitTest extends TestCase
{
    /**
     * Anonymous class that exposes the protected translate() method for testing.
     */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new class {
            use TranslationTrait;

            public function callTranslate(string $key, string $fallback): string
            {
                return $this->translate($key, $fallback);
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function translateReturnsTranslatedStringWhenLanguageServiceAvailable(): void
    {
        $langMock = $this->createMock(LanguageService::class);
        $langMock
            ->method('sL')
            ->with('LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:some.key')
            ->willReturn(
                'Translated value',
            );
        $GLOBALS['LANG'] = $langMock;
        $result = $this->subject->callTranslate('some.key', 'Fallback value');
        self::assertSame('Translated value', $result);
    }

    #[Test]
    public function translateReturnsFallbackWhenLanguageServiceIsUnavailable(): void
    {
        unset($GLOBALS['LANG']);
        $result = $this->subject->callTranslate('some.key', 'Fallback value');
        self::assertSame('Fallback value', $result);
    }

    #[Test]
    public function translateReturnsFallbackWhenGlobalsLangIsNotLanguageService(): void
    {
        $GLOBALS['LANG'] = 'not-a-language-service-object';
        $result = $this->subject->callTranslate('some.key', 'Fallback value');
        self::assertSame('Fallback value', $result);
    }

    #[Test]
    public function translateReturnsFallbackWhenGlobalsLangIsNull(): void
    {
        $GLOBALS['LANG'] = null;
        $result = $this->subject->callTranslate('some.key', 'Fallback value');
        self::assertSame('Fallback value', $result);
    }

    #[Test]
    public function translateReturnsFallbackWhenTranslationIsEmpty(): void
    {
        $langMock = $this->createMock(LanguageService::class);
        $langMock
            ->method('sL')
            ->willReturn('');
        $GLOBALS['LANG'] = $langMock;
        $result = $this->subject->callTranslate('missing.key', 'My fallback');
        self::assertSame('My fallback', $result);
    }

    #[Test]
    public function translateBuildsCorrectLocallangPathFromKey(): void
    {
        $langMock = $this->createMock(LanguageService::class);
        $langMock
            ->expects(self::once())
            ->method('sL')
            ->with(
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:flash.success',
            )
            ->willReturn('Success');
        $GLOBALS['LANG'] = $langMock;
        $this->subject->callTranslate('flash.success', 'Success fallback');
    }

    #[Test]
    public function translateWithEmptyKeyStillCallsLanguageService(): void
    {
        $langMock = $this->createMock(LanguageService::class);
        $langMock
            ->expects(self::once())
            ->method('sL')
            ->with(
                'LLL:EXT:nr_passkeys_be/Resources/Private/Language/locallang.xlf:',
            )
            ->willReturn('');
        $GLOBALS['LANG'] = $langMock;
        $result = $this->subject->callTranslate('', 'empty key fallback');
        self::assertSame('empty key fallback', $result);
    }
}
