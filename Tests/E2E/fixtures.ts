/**
 * The `test` every spec in this directory imports.
 *
 * It is Playwright's own, with one automatic fixture: the extension's
 * rate-limit counters are cleared before each test. Without it the suite
 * throttles itself — see rate-limit.ts — and a spec's verdict depends on how
 * many login requests the specs before it happened to make.
 *
 * A spec that wants to observe the limiter still can: the reset runs before the
 * test, not during it, so a burst inside one test trips the limiter exactly as
 * it would in production.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { test as base, expect } from '@playwright/test';

import { resetRateLimiter } from './rate-limit';

export const test = base.extend<{ freshRateLimitBudget: void }>({
    freshRateLimitBudget: [
        async ({}, use) => {
            resetRateLimiter();
            await use();
        },
        { auto: true },
    ],
});

export { expect };
