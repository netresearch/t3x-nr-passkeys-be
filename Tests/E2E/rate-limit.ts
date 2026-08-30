/**
 * Clearing the extension's rate-limit cache from the test side.
 *
 * `RateLimiterService` counts per IP and endpoint in a TYPO3 FileBackend cache,
 * and every spec reaches the instance from the same address. The default budget
 * is 10 attempts per 300 seconds — less than one full suite run — so without a
 * reset the specs that run later are throttled by the ones that ran before
 * them, and which specs fail depends on the order they happen to execute in.
 * The same cache holds the lockout counters, so both are cleared together.
 *
 * The cache is reachable because `runTests.sh -s e2e` mounts the repository
 * into the Playwright container under its own path, with the provisioned
 * instance inside it. Against an instance somewhere else there is nothing to
 * clear and nothing to do: the reset reports that it cleared nothing, and the
 * caller carries on.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import * as fs from 'fs';
import * as path from 'path';

const projectRoot = path.resolve(__dirname, '../..');

let warnedAboutPermissions = false;

/**
 * Where the cache can live: the instance `-s e2e` provisions, a TYPO3 in the
 * repository itself (composer layout), and the pre-v12 typo3temp layout.
 */
export const rateLimitCacheDirs = [
    path.join(projectRoot, '.Build/e2e-typo3/var/cache/data/nr_passkeys_be_ratelimit'),
    path.join(projectRoot, 'var/cache/data/nr_passkeys_be_ratelimit'),
    path.join(projectRoot, '.Build/Web/typo3temp/var/cache/data/nr_passkeys_be_ratelimit'),
];

/**
 * Remove every rate-limit cache directory that exists.
 *
 * @returns the directories actually removed, empty when the instance is not
 *          reachable from here.
 */
export function resetRateLimiter(): string[] {
    const cleared: string[] = [];
    const notPermitted: string[] = [];

    for (const dir of rateLimitCacheDirs) {
        if (!fs.existsSync(dir)) {
            continue;
        }

        try {
            fs.rmSync(dir, { recursive: true, force: true });
            cleared.push(dir);
        } catch {
            // Expected against the instance `-s e2e` provisions: PHP-FPM runs
            // as root there, so the counter files belong to root and their
            // directory is not group-writable — EACCES from the Playwright
            // container, which runs as somebody else. That instance keeps its
            // budget fresh a different way: runTests.conf configures a short
            // rateLimitWindowSeconds, so the counters expire between tests
            // instead of being deleted.
            //
            // Renaming the directory looks like a way around the permission
            // (the parent is world-writable) and is not one: PHP-FPM resolves
            // the old path from its realpath cache for another two minutes and
            // keeps counting into the directory that was moved aside, so the
            // limiter goes on refusing while the fresh directory sits unused.
            notPermitted.push(dir);
        }
    }

    if (cleared.length === 0 && notPermitted.length > 0 && !warnedAboutPermissions) {
        // Once per process. Sixty identical warnings, one per test, say nothing
        // the first one did not.
        warnedAboutPermissions = true;
        console.warn(
            `Rate limit cache is not writable from here (${notPermitted.join(', ')}); `
            + 'relying on rateLimitWindowSeconds to expire the counters.',
        );
    }

    return cleared;
}
