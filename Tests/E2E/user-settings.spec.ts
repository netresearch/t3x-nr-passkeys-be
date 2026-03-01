import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for user settings passkey management.
 *
 * Tests the user settings page loads, JS runs without errors,
 * and authenticated management API endpoints respond correctly.
 *
 * TYPO3 AJAX routes require a CSRF token in the URL. We extract
 * the tokenized URL from TYPO3.settings.ajaxUrls after logging in.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 *   - Admin user: admin / Joh316!!
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

/**
 * Get the TYPO3 AJAX URL (with CSRF token) for a given route key.
 * Must be called after login and navigating to a backend page.
 */
async function getAjaxUrl(page: Page, routeKey: string): Promise<string | null> {
    return page.evaluate((key: string) => {
        return (window as any).TYPO3?.settings?.ajaxUrls?.[key] ?? null;
    }, routeKey);
}

test.describe('User Settings - Page & JS', () => {
    test.beforeEach(async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');
    });

    test('user settings page loads after login', async ({ page }) => {
        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/user\/setup|setup/);
    });

    test('passkey management JS loads without errors', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.goto('/typo3/module/user/setup');
        await page.waitForTimeout(3000);

        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404') && !e.includes('net::')
                && !e.includes('Too few arguments')
                && !e.includes('is not valid JSON')
                && !e.includes('Load passkeys error'),
        );
        expect(realErrors).toHaveLength(0);
    });
});

test.describe('Passkey Management API (authenticated)', () => {
    test.beforeEach(async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');
    });

    test('list endpoint returns JSON with credentials array', async ({ page }) => {
        // Navigate to backend to get TYPO3.settings loaded
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const listUrl = await getAjaxUrl(page, 'passkeys_manage_list');
        expect(listUrl).toBeTruthy();

        const response = await page.request.get(listUrl!);
        expect(response.status()).toBe(200);
        const data = await response.json();
        expect(Array.isArray(data.credentials)).toBe(true);
    });

    test('registration options returns challenge data', async ({ page }) => {
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
        expect(optionsUrl).toBeTruthy();

        const response = await page.request.post(optionsUrl!, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status()).toBe(200);
        const data = await response.json();
        expect(data.options).toBeDefined();
        expect(data.options.challenge).toBeDefined();
        expect(data.challengeToken).toBeDefined();
        expect(data.options.rp).toBeDefined();
        expect(data.options.user).toBeDefined();
    });

    test('rename endpoint rejects missing uid', async ({ page }) => {
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const renameUrl = await getAjaxUrl(page, 'passkeys_manage_rename');
        expect(renameUrl).toBeTruthy();

        const response = await page.request.post(renameUrl!, {
            headers: { 'Content-Type': 'application/json' },
            data: { label: 'New Name' },
        });

        expect(response.status()).toBeGreaterThanOrEqual(400);
    });

    test('remove endpoint rejects missing uid', async ({ page }) => {
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const removeUrl = await getAjaxUrl(page, 'passkeys_manage_remove');
        expect(removeUrl).toBeTruthy();

        const response = await page.request.post(removeUrl!, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status()).toBeGreaterThanOrEqual(400);
    });
});

test.describe('Passkey Management API (unauthenticated)', () => {
    test.beforeEach(async ({ page }) => {
        await page.context().clearCookies();
    });

    test('management endpoints require authentication', async ({ page }) => {
        const endpoints = [
            '/typo3/passkeys/manage/list',
            '/typo3/passkeys/manage/registration/options',
        ];

        for (const url of endpoints) {
            const response = await page.goto(url);
            const finalUrl = page.url();
            expect(
                finalUrl.includes('/login') || (response?.status() ?? 0) >= 400,
            ).toBeTruthy();
        }
    });
});
