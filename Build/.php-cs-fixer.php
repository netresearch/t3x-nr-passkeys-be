<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$createConfig = require __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/php-cs-fixer/config.php';

$config = $createConfig(
    "Copyright (c) 2025-2026 Netresearch DTT GmbH\nSPDX-License-Identifier: GPL-2.0-or-later",
    __DIR__ . '/..',
    [
        // The risky half of PER-CS 3.0, which the shared set does not enable:
        // no_trailing_whitespace_in_string, no_unreachable_default_argument_value.
        '@PER-CS3x0:risky' => true,
        'strict_param'     => true,
        // Blank lines. A file written back from a syntax tree keeps only what a
        // rule describes, so the ones this code base actually keeps are stated
        // here rather than left to whoever edits next. The shared set separates
        // methods only and puts a blank line before `return` only.
        'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
        'blank_line_before_statement' => [
            'statements' => [
                'break',
                'continue',
                'declare',
                'do',
                'exit',
                'for',
                'foreach',
                'goto',
                'if',
                'phpdoc',
                'return',
                'switch',
                'throw',
                'try',
                'while',
                'yield',
            ],
        ],
        // After a block. No shipped fixer writes one there, and it is the rule
        // this code keeps most consistently — 232 of 247 places, against 37 %
        // for the blank line before an `if` that the shipped rule enforces.
        'Netresearch/blank_line_after_control_structure' => true,
        // Before a comment on a line of its own — 274 of 309 places here, 89 %.
        // `blank_line_before_statement` covers docblocks; a `//` comment is not
        // a statement, and no shipped fixer writes in front of one.
        'Netresearch/blank_line_before_comment' => true,
        // Every call of a chain on its own line from two calls on. Nothing in
        // php-cs-fixer breaks a line, so a chain written on one line stays on
        // one line however long it grows once code is generated.
        'Netresearch/break_long_method_chain' => ['minimum_links' => 2],
    ],
    // canonical-formatting.yml checks the printer out into .printer/ inside
    // the working tree before it runs `composer ci:cgl`.
    ['.printer'],
);

// The shared finder skips dot files; this file was formatted until now and
// the canonical printer rewrites it, so it stays in the fixer's scope.
$config
    ->getFinder()
    ->append([__FILE__]);

return $config;
