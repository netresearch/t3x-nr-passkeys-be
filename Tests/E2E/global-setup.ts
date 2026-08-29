/**
 * Playwright global setup - runs once before all E2E tests.
 *
 * Clears the extension's rate-limit cache so a run does not start against
 * counters an earlier run left behind. The per-test reset in fixtures.ts uses
 * the same helper; this one covers the state before the first test.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { execFileSync } from 'child_process';
import * as path from 'path';

import { resetRateLimiter } from './rate-limit';

export default function globalSetup(): void {
    const cleared = resetRateLimiter();

    for (const dir of cleared) {
        console.log(`Cleared rate limit cache: ${dir}`);
    }

    if (cleared.length > 0) {
        return;
    }

    // Nothing on disk to clear: either the run points at an instance elsewhere,
    // or nothing has written a counter yet. A TYPO3 in this repository can
    // still be reached through its CLI.
    try {
        execFileSync(
            'php',
            [path.join(path.resolve(__dirname, '../..'), '.Build/bin/typo3'), 'cache:flush', '--group=system'],
            { timeout: 10_000, stdio: 'pipe' },
        );
    } catch {
        // Non-fatal: there may be no local instance and no cache to flush.
        console.warn('Warning: Could not flush rate limit cache.');
    }
}
