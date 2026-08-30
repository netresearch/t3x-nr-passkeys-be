import { APIResponse, Page } from '@playwright/test';
import { test, expect } from './fixtures';

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
 *   - TYPO3 instance running (via `./Build/Scripts/runTests.sh -s e2e` or TYPO3_BASE_URL)
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

/**
 * POST an empty body to the registration-options route.
 */
async function requestRegistrationOptions(page: Page, url: string): Promise<APIResponse> {
    return page.request.post(url, {
        headers: { 'Content-Type': 'application/json' },
        data: {},
    });
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

    test('passkey management panel renders on the setup page', async ({ page }) => {
        // Regression guard for the v0.9.1/v0.9.2 fix: on TYPO3 14 a bare
        // type=user column (without renderType) made SingleFieldContainer
        // throw and the panel never rendered. The panel container, add button
        // and list table are emitted server-side by
        // PasskeySettingsPanel::buildHtml(); their absence means the FormEngine
        // wiring is broken.
        //
        // The setup module renders inside the backend module iframe
        // (#typo3-contentIframe), and the passkey field lives in a non-default
        // settings tab. So pierce the iframe and assert the panel is attached
        // to the DOM rather than visible (asserting visibility would require
        // driving the tab UI, which is brittle and orthogonal to "did the
        // panel render").
        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const moduleFrame = page.frameLocator('#typo3-contentIframe');
        await expect(moduleFrame.locator('#passkey-management-container')).toBeAttached();
        await expect(moduleFrame.locator('#passkey-add-btn')).toBeAttached();
        await expect(moduleFrame.locator('#passkey-list-table')).toBeAttached();
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

        // Only infrastructure noise is filtered. Passkey-specific errors
        // ("Too few arguments", "Load passkeys error", invalid JSON) are NOT
        // suppressed — those are exactly the symptoms of a broken panel and
        // must fail the test.
        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404') && !e.includes('net::'),
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

    test('registration options requires sudo mode', async ({ page }) => {
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
        expect(optionsUrl).toBeTruthy();

        const response = await requestRegistrationOptions(page, optionsUrl!);

        // The route declares sudoMode in Configuration/Backend/AjaxRoutes.php:
        // enrolling a credential must not be reachable from a session someone
        // walked away from. TYPO3's SudoModeInterceptor answers 422 and hands
        // back the URI that takes the step-up.
        expect(response.status()).toBe(422);
        const initialization = (await response.json()).sudoModeInitialization;
        expect(initialization?.verifyActionUri).toBeTruthy();
    });

    test('registration options returns challenge data after the step-up', async ({ page }) => {
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
        expect(optionsUrl).toBeTruthy();

        const challenged = await requestRegistrationOptions(page, optionsUrl!);
        expect(challenged.status()).toBe(422);

        const { verifyActionUri } = (await challenged.json()).sudoModeInitialization;
        const verified = await page.request.post(verifyActionUri, {
            form: { password: ADMIN_PASS },
        });
        expect(verified.status()).toBe(200);
        expect((await verified.json()).message).toBe('accessGranted');

        const response = await requestRegistrationOptions(page, optionsUrl!);

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
