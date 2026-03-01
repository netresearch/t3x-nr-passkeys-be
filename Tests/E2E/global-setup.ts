/**
 * Playwright global setup - runs once before all E2E tests.
 *
 * Clears the TYPO3 rate limit cache files to ensure a clean state.
 * The IP-based rate limiter persists across test runs via FileBackend cache,
 * so previous test runs can leave stale entries that cause 429 responses.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { execFileSync } from 'child_process';

export default function globalSetup(): void {
    try {
        // Clear the extension's rate limit cache files directly.
        // TYPO3's `cache:flush` does not reliably flush extension caches.
        execFileSync('ddev', [
            'exec',
            'rm -rf /var/www/html/v13/var/cache/data/nr_passkeys_be_ratelimit/*',
        ], { timeout: 10_000, stdio: 'pipe' });
    } catch {
        // DDEV might not be available (CI uses different setup) — non-fatal
        console.warn('Warning: Could not flush rate limit cache via ddev.');
    }
}
