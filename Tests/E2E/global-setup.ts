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
import { execSync } from 'child_process';
import * as path from 'path';
import * as fs from 'fs';

export default function globalSetup(): void {
    // Try to clear rate limit cache via filesystem first (works in CI and local)
    const projectRoot = path.resolve(__dirname, '../..');
    const cacheDirs = [
        path.join(projectRoot, '.Build/web/var/cache/data/nr_passkeys_be_ratelimit'),
        path.join(projectRoot, '.Build/web/typo3temp/var/cache/data/nr_passkeys_be_ratelimit'),
    ];

    let cleared = false;
    for (const cacheDir of cacheDirs) {
        if (fs.existsSync(cacheDir)) {
            try {
                fs.rmSync(cacheDir, { recursive: true, force: true });
                console.log(`Cleared rate limit cache: ${cacheDir}`);
                cleared = true;
            } catch {
                console.warn(`Warning: Could not clear cache dir: ${cacheDir}`);
            }
        }
    }

    if (!cleared) {
        // Fallback: try TYPO3 CLI cache flush
        try {
            execSync(
                `php ${path.join(projectRoot, '.Build/bin/typo3')} cache:flush --group=system`,
                { timeout: 10_000, stdio: 'pipe' },
            );
        } catch {
            // Non-fatal: cache dir may not exist yet on first run
            console.warn('Warning: Could not flush rate limit cache.');
        }
    }
}
