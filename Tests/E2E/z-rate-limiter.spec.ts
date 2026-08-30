import { Page } from '@playwright/test';
import { test, expect } from './fixtures';

/**
 * Login API behaviour under a burst of requests.
 *
 * The z- prefix keeps this file last, from when it deliberately exhausted the
 * IP rate limit and everything after it would have been refused. It no longer
 * does: what the limiter does when a budget runs out is asserted directly, and
 * deterministically, in Tests/Unit/Service/RateLimiterServiceTest.php — an
 * end-to-end browser test cannot drive a shared per-IP counter without taking
 * the rest of the suite's budget with it. What is left here is the part only
 * this level can see: that the endpoint keeps to its contract when called
 * rapidly, rather than answering 500.
 *
 * Prerequisites:
 *   - TYPO3 instance running (via `./Build/Scripts/runTests.sh -s e2e` or TYPO3_BASE_URL)
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

const ADMIN_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.TYPO3_ADMIN_PASS || 'Joh316!!';

async function loginAsAdmin(page: Page): Promise<boolean> {
    await page.goto('/typo3/login');
    await page.waitForLoadState('networkidle');

    const usernameInput = page.locator('input[name="username"]');
    const passwordInput = page.locator('input[name="p_field"]');

    if (!await usernameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        return false;
    }

    await usernameInput.fill(ADMIN_USER);
    await passwordInput.fill(ADMIN_PASS);

    await page.locator('#t3-login-submit').click();
    await page.waitForLoadState('networkidle');

    return !page.url().includes('/login');
}

test.describe('Login API - Rate Limiting', () => {
    test.beforeEach(async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');
    });

    test('rapid requests stay inside the endpoint contract', async ({ page }) => {
        const statuses: number[] = [];

        for (let i = 0; i < 12; i++) {
            const response = await page.request.post('/typo3/passkeys/login/options', {
                headers: { 'Content-Type': 'application/json' },
                data: { username: `ratelimit_test_${i}` },
            });
            statuses.push(response.status());
        }

        expect(statuses.length).toBe(12);

        // 200 belongs in this list: for an unknown user the endpoint answers
        // with decoy options on purpose, so that a caller cannot tell existing
        // usernames apart from invented ones — api-endpoints.spec.ts asserts
        // that behaviour directly. 401 is a refused login, 429 the rate limit.
        // Nothing else is a valid answer here, and a 500 under a burst is what
        // this test would catch.
        statuses.forEach((status: number) => {
            expect([200, 401, 429]).toContain(status);
        });
    });
});
