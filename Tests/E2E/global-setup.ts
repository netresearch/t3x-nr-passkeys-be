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
import * as path from 'path';
import * as fs from 'fs';

export default function globalSetup(): void {
    // Try to clear rate limit cache via filesystem first (works in CI and local)
    const projectRoot = path.resolve(__dirname, '../..');
    // .Build/e2e-typo3 is where `runTests.sh -s e2e` installs the instance, and
    // the directory is mounted into the Playwright container under the same
    // path. The two entries below it are the pre-v12 and composer layouts of a
    // TYPO3 that lives in the repository itself, which is what the DDEV setup
    // and an external TYPO3_BASE_URL target may still use.
    const cacheDirs = [
        path.join(projectRoot, '.Build/e2e-typo3/var/cache/data/nr_passkeys_be_ratelimit'),
        path.join(projectRoot, '.Build/Web/typo3temp/var/cache/data/nr_passkeys_be_ratelimit'),
        path.join(projectRoot, 'var/cache/data/nr_passkeys_be_ratelimit'),
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
            execFileSync(
                'php',
                [path.join(projectRoot, '.Build/bin/typo3'), 'cache:flush', '--group=system'],
                { timeout: 10_000, stdio: 'pipe' },
            );
        } catch {
            // Non-fatal: cache dir may not exist yet on first run
            console.warn('Warning: Could not flush rate limit cache.');
        }
    }
}
