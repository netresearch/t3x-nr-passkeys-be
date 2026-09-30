/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    resolve: {
        alias: [
            // TYPO3 resolves this import map entry at runtime; vitest needs it
            // spelled out so a test can import the shipped login module itself
            // rather than a copy of its logic.
            {
                find: '@netresearch/nr-passkeys-be',
                replacement: fileURLToPath(new URL('./Resources/Public/JavaScript', import.meta.url)),
            },
            // Core modules exist only in a running backend. The four a module
            // talks to get stubs that record what it sent and showed; every
            // other @typo3/* specifier resolves to the generic stub.
            {
                find: /^@typo3\/core\/ajax\/ajax-request\.js$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/ajax-request.js', import.meta.url)),
            },
            {
                find: /^@typo3\/backend\/notification\.js$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/notification.js', import.meta.url)),
            },
            {
                find: /^@typo3\/backend\/modal\.js$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/modal.js', import.meta.url)),
            },
            {
                find: /^@typo3\/core\/event\/regular-event\.js$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/regular-event.js', import.meta.url)),
            },
            {
                find: /^@typo3\/.*$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/typo3.js', import.meta.url)),
            },
        ],
    },
    test: {
        include: ['Tests/JavaScript/**/*.test.{js,ts}'],
        environment: 'jsdom',
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['Resources/Public/JavaScript/**/*.js'],
        },
    },
});
