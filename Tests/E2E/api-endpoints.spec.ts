import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for API endpoint behavior - input validation, error handling, admin endpoints.
 *
 * Login API routes (Routes.php with access=public) work without CSRF tokens.
 * Admin/Management AJAX routes (AjaxRoutes.php) require CSRF tokens — we extract
 * tokenized URLs from TYPO3.settings.ajaxUrls after logging in.
 *
 * Prerequisites:
 *   - TYPO3 instance running (via `./Build/Scripts/runTests.sh e2e` or TYPO3_BASE_URL)
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
 */
async function getAjaxUrl(page: Page, routeKey: string): Promise<string | null> {
    return page.evaluate((key: string) => {
        return (window as any).TYPO3?.settings?.ajaxUrls?.[key] ?? null;
    }, routeKey);
}

test.describe('Login API - Validated with Session', () => {
    test.beforeEach(async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');
    });

    test('login options returns JSON for valid username', async ({ page }) => {
        // Login routes are public (no CSRF token needed)
        const response = await page.request.post('/typo3/passkeys/login/options', {
            headers: { 'Content-Type': 'application/json' },
            data: { username: 'admin' },
        });

        expect(response.status()).toBe(200);
        const contentType = response.headers()['content-type'] || '';
        expect(contentType).toContain('application/json');

        const body = await response.json();
        expect(body.options).toBeDefined();
        expect(body.options.challenge).toBeDefined();
        expect(body.challengeToken).toBeDefined();
    });

    test('login options returns discoverable options for empty username', async ({ page }) => {
        const response = await page.request.post('/typo3/passkeys/login/options', {
            headers: { 'Content-Type': 'application/json' },
            data: { username: '' },
        });

        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.options).toBeDefined();
        expect(body.challengeToken).toBeDefined();
        expect(body.options.allowCredentials).toEqual([]);
    });

    test('login options treats missing username key as discoverable flow', async ({ page }) => {
        const response = await page.request.post('/typo3/passkeys/login/options', {
            headers: { 'Content-Type': 'application/json' },
            data: { foo: 'bar' },
        });

        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.options).toBeDefined();
        expect(body.options.allowCredentials).toEqual([]);
    });

    test('login options returns 401 for non-existent user', async ({ page }) => {
        const response = await page.request.post('/typo3/passkeys/login/options', {
            headers: { 'Content-Type': 'application/json' },
            data: { username: 'nonexistent_user_xyz_12345' },
        });

        expect(response.status()).toBe(401);
        const body = await response.json();
        expect(body.error).toContain('failed');
    });

    test('login verify rejects missing fields with 400', async ({ page }) => {
        const response = await page.request.post('/typo3/passkeys/login/verify', {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status()).toBe(400);
        const body = await response.json();
        expect(body.error).toContain('required');
    });

    test('login options response has JSON content type', async ({ page }) => {
        const response = await page.request.post('/typo3/passkeys/login/options', {
            headers: { 'Content-Type': 'application/json' },
            data: { username: 'admin' },
        });

        const contentType = response.headers()['content-type'] || '';
        expect(contentType).toContain('application/json');
    });
});

test.describe('Login API - Route Method Handling', () => {
    test('GET to login options returns 405 or redirects', async ({ page }) => {
        const response = await page.goto('/typo3/passkeys/login/options');
        const finalUrl = page.url();
        expect(
            finalUrl.includes('/login') || (response?.status() ?? 0) >= 400,
        ).toBeTruthy();
    });
});

test.describe('Admin API Endpoints', () => {
    test.beforeEach(async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');
        // Navigate to backend to load TYPO3.settings
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
    });

    test('admin list endpoint returns JSON with credentials', async ({ page }) => {
        const listUrl = await getAjaxUrl(page, 'passkeys_admin_list');
        expect(listUrl).toBeTruthy();

        // Admin list requires beUserUid parameter (uid=1 = admin user)
        const separator = listUrl!.includes('?') ? '&' : '?';
        const response = await page.request.get(`${listUrl}${separator}beUserUid=1`);
        expect(response.status()).toBe(200);
        const contentType = response.headers()['content-type'] || '';
        expect(contentType).toContain('application/json');

        const data = await response.json();
        expect(Array.isArray(data.credentials)).toBe(true);
    });

    test('admin remove endpoint rejects missing uid', async ({ page }) => {
        const removeUrl = await getAjaxUrl(page, 'passkeys_admin_remove');
        expect(removeUrl).toBeTruthy();

        const response = await page.request.post(removeUrl!, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status()).toBeGreaterThanOrEqual(400);
    });

    test('admin unlock endpoint rejects missing uid', async ({ page }) => {
        const unlockUrl = await getAjaxUrl(page, 'passkeys_admin_unlock');
        expect(unlockUrl).toBeTruthy();

        const response = await page.request.post(unlockUrl!, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        expect(response.status()).toBeGreaterThanOrEqual(400);
    });
});

test.describe('Admin API - Unauthenticated Access', () => {
    test.beforeEach(async ({ page }) => {
        await page.context().clearCookies();
    });

    test('admin endpoints reject unauthenticated requests', async ({ page }) => {
        const endpoints = [
            '/typo3/passkeys/admin/list',
            '/typo3/passkeys/admin/remove',
            '/typo3/passkeys/admin/unlock',
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
