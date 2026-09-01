<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Utility;

use Netresearch\NrPasskeysBe\Utility\TypeCastTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(TypeCastTrait::class)]
final class TypeCastTraitTest extends TestCase
{
    /**
     * Anonymous class that exposes TypeCastTrait static methods publicly for testing.
     */
    private static object $subject;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$subject = new class {
            use TypeCastTrait;

            public static function callIntVal(mixed $value, int $default = 0): int
            {
                return self::intVal($value, $default);
            }

            public static function callStringVal(mixed $value, string $default = ''): string
            {
                return self::stringVal($value, $default);
            }
        };
    }

    // --- intVal tests ---
    /**
     * @return array<string, array{mixed, int, int}>
     */
    public static function intValNumericInputsProvider(): array
    {
        return [
            'integer zero' => [0, 0, 0],
            'positive integer' => [42, 0, 42],
            'negative integer' => [-7, 0, -7],
            'integer as string' => ['123', 0, 123],
            'negative integer as string' => ['-5', 0, -5],
            'float truncates to int' => [3.9, 0, 3],
            'float as string' => ['2.7', 0, 2],
            'zero as string' => ['0', 0, 0],
        ];
    }

    #[Test]
    #[DataProvider('intValNumericInputsProvider')]
    public function intValConvertsNumericInputs(mixed $value, int $default, int $expected): void
    {
        $result = self::$subject::callIntVal($value, $default);
        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function intValNonNumericInputsProvider(): array
    {
        return [
            'null uses default' => [null, 0],
            'boolean true uses default' => [true, 0],
            'boolean false uses default' => [false, 0],
            'empty string uses default' => ['', 0],
            'non-numeric string uses default' => ['hello', 0],
            'array uses default' => [[], 0],
            'object uses default' => [new stdClass(), 0],
        ];
    }

    #[Test]
    #[DataProvider('intValNonNumericInputsProvider')]
    public function intValReturnsDefaultForNonNumericInputs(mixed $value, int $defaultPlaceholder): void
    {
        $customDefault = 99;
        $result = self::$subject::callIntVal($value, $customDefault);
        self::assertSame($customDefault, $result);
    }

    #[Test]
    public function intValUsesZeroAsDefaultWhenNotProvided(): void
    {
        $result = self::$subject::callIntVal(null);
        self::assertSame(0, $result);
    }

    #[Test]
    public function intValUsesCustomDefault(): void
    {
        $result = self::$subject::callIntVal('not-a-number', 42);
        self::assertSame(42, $result);
    }

    // --- stringVal tests ---
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function stringValStringInputsProvider(): array
    {
        return [
            'empty string' => ['', ''],
            'non-empty string' => ['hello', 'hello'],
            'string with spaces' => ['  spaced  ', '  spaced  '],
            'numeric string' => ['42', '42'],
            'string with special chars' => ['foo@bar.com', 'foo@bar.com'],
        ];
    }

    #[Test]
    #[DataProvider('stringValStringInputsProvider')]
    public function stringValReturnsStringInputUnchanged(mixed $value, string $expected): void
    {
        $result = self::$subject::callStringVal($value, 'default');
        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function stringValNonStringInputsProvider(): array
    {
        return [
            'null' => [null],
            'integer' => [42],
            'float' => [3.14],
            'boolean true' => [true],
            'boolean false' => [false],
            'array' => [[]],
            'object' => [new stdClass()],
        ];
    }

    #[Test]
    #[DataProvider('stringValNonStringInputsProvider')]
    public function stringValReturnsDefaultForNonStringInputs(mixed $value): void
    {
        $customDefault = 'my-default';
        $result = self::$subject::callStringVal($value, $customDefault);
        self::assertSame($customDefault, $result);
    }

    #[Test]
    public function stringValUsesEmptyStringAsDefaultWhenNotProvided(): void
    {
        $result = self::$subject::callStringVal(null);
        self::assertSame('', $result);
    }

    #[Test]
    public function stringValUsesCustomDefault(): void
    {
        $result = self::$subject::callStringVal(42, 'fallback');
        self::assertSame('fallback', $result);
    }
}
