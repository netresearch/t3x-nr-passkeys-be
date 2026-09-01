<?php

declare(strict_types=1);

use Netresearch\Typo3CiWorkflows\Fixer\BlankLineAfterControlStructureFixer;
use Netresearch\Typo3CiWorkflows\Fixer\BreakLongMethodChainFixer;

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/../Classes')
    ->in(__DIR__ . '/../Tests')
    ->in(__DIR__ . '/../Configuration');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    // Shipped by netresearch/typo3-ci-workflows, registered but not enabled
    // there: reflowing a code base is a decision each project makes for itself.
    ->registerCustomFixers([
        new BlankLineAfterControlStructureFixer(),
        new BreakLongMethodChainFixer(),
    ])
    ->setRules([
        '@PER-CS3.0' => true,
        '@PER-CS3.0:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        // Blank lines. A file written back from a syntax tree keeps only what a
        // rule describes, so the ones this code base actually keeps are stated
        // here rather than left to whoever edits next.
        'class_attributes_separation' => [
            'elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one'],
        ],
        'blank_line_before_statement' => [
            'statements' => [
                'break', 'continue', 'declare', 'do', 'exit', 'for', 'foreach', 'goto', 'if',
                'phpdoc', 'return', 'switch', 'throw', 'try', 'while', 'yield',
            ],
        ],
        // After a block. No shipped fixer writes one there, and it is the rule
        // this code keeps most consistently — 232 of 247 places, against 37 %
        // for the blank line before an `if` that the shipped rule enforces.
        'Netresearch/blank_line_after_control_structure' => true,
        // Every call of a chain on its own line from three calls on. Nothing in
        // php-cs-fixer breaks a line, so a chain written on one line stays on
        // one line however long it grows once code is generated.
        'Netresearch/break_long_method_chain' => ['minimum_links' => 3],
        'declare_strict_types' => true,
        // @PER-CS3.0 has no opinion on the space before the parentheses, so `declare
        // (strict_types=1);` passes the gate. Hand-written code does not produce it, but
        // anything generated or rewritten through an AST does, and then stays that way.
        'declare_parentheses' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'native_function_invocation' => ['include' => ['@all']],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'single_line_throw' => false,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);
