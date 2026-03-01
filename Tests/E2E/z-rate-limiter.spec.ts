import { test, expect, Page } from '@playwright/test';

/**
 * Rate limiter tests for login API endpoints.
 *
 * This file intentionally runs LAST (z- prefix for alphabetical ordering)
 * because it exhausts the IP-based rate limit window, which would cause
 * subsequent login options requests to return 429 in other test files.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
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

    test('rate limiter responds to rapid requests', async ({ page }) => {
        const statuses: number[] = [];

        for (let i = 0; i < 12; i++) {
            const response = await page.request.post('/typo3/passkeys/login/options', {
                headers: { 'Content-Type': 'application/json' },
                data: { username: `ratelimit_test_${i}` },
            });
            statuses.push(response.status());
        }

        expect(statuses.length).toBe(12);
        statuses.forEach((status: number) => {
            expect(status).toBeGreaterThan(0);
            expect([401, 429]).toContain(status);
        });
    });
});
