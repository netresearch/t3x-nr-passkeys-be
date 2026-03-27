import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for the passkey onboarding banner.
 *
 * Uses page.route() to mock the AJAX enforcement-status endpoint so the
 * banner always renders — no dependency on live enforcement configuration.
 *
 * Tests that the banner appears in the correct location (inside .module-body,
 * NOT as a third pane alongside the page tree), can be dismissed, and
 * has correct accessibility attributes.
 *
 * Prerequisites:
 *   - TYPO3 instance running (via `./Build/Scripts/runTests.sh e2e` or TYPO3_BASE_URL)
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
 * Mock the enforcement status AJAX endpoint to force the banner to render.
 * The banner JS fetches TYPO3.settings.ajaxUrls.passkeys_enforcement_status
 * which resolves to a URL like /typo3/ajax/passkeys/enforcement-status.
 */
async function mockEnforcementStatus(page: Page, data: Record<string, unknown> = {}): Promise<void> {
    const defaults = {
        requiresBanner: true,
        gracePeriodRemainingDays: 7,
        nudgeUntil: 0,
    };
    const response = { ...defaults, ...data };

    await page.route('**/ajax/passkeys/enforcement**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(response),
        });
    });
}

/**
 * Find the banner in the page or any of its iframes.
 */
async function findBanner(page: Page): Promise<{ found: boolean; frame: Page | any }> {
    const framesToCheck = [page, ...page.frames()];
    for (const frame of framesToCheck) {
        if (await frame.locator('.passkey-setup-banner').count().catch(() => 0) > 0) {
            return { found: true, frame };
        }
    }
    return { found: false, frame: page };
}

test.describe('Passkey Onboarding Banner', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('banner JS loads without console errors', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404') && !e.includes('net::')
                && !e.includes('is not valid JSON')
                && !e.includes('Load passkeys error'),
        );
        expect(realErrors).toHaveLength(0);
    });

    test('banner is NOT placed in .scaffold-content (third pane bug)', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        // Mock enforcement so the banner renders
        await mockEnforcementStatus(page);
        // Clear sessionStorage dismissal
        await page.evaluate(() => sessionStorage.removeItem('nr-passkeys-banner-dismissed'));

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        // Banner must NEVER be a direct child of .scaffold-content
        const framesToCheck = [page, ...page.frames()];
        for (const frame of framesToCheck) {
            const bannerInScaffold = frame.locator('.scaffold-content > .passkey-setup-banner');
            expect(await bannerInScaffold.count().catch(() => 0)).toBe(0);
        }
    });
});

test.describe('Passkey Onboarding Banner (mocked enforcement)', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
        if (!isLoggedIn) return;

        // Mock enforcement API and clear dismiss state BEFORE navigating
        await mockEnforcementStatus(page, { requiresBanner: true, gracePeriodRemainingDays: 7, nudgeUntil: 0 });
        await page.evaluate(() => sessionStorage.removeItem('nr-passkeys-banner-dismissed'));
    });

    test('banner renders inside content module container', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        // Verify parent is the scaffold-content-module (v12/v13) or the module
        // router's parent div (v14) — NOT .scaffold-content directly
        const parentInfo = await frame.evaluate(() => {
            const banner = document.querySelector('.passkey-setup-banner');
            const parent = banner?.parentElement;
            return {
                className: parent?.className || '',
                hasRouter: !!parent?.querySelector('typo3-backend-module-router'),
            };
        });
        const isValidParent = /scaffold-content-module/.test(parentInfo.className)
            || parentInfo.hasRouter;
        expect(isValidParent).toBe(true);
    });

    test('banner has correct accessibility attributes', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const banner = frame.locator('.passkey-setup-banner');
        await expect(banner).toHaveAttribute('role', 'status');
        await expect(banner).toHaveAttribute('aria-live', 'polite');
    });

    test('banner shows grace period countdown in title', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const title = await frame.locator('.passkey-setup-banner strong').textContent();
        expect(title).toContain('7 days remaining');
    });

    test('banner shows passkey explanation and docs link', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const desc = await frame.locator('.passkey-banner-description').textContent();
        expect(desc).toContain('fingerprint');
        expect(desc).toContain('phishing');

        const learnMore = frame.locator('.passkey-banner-description a');
        await expect(learnMore).toHaveAttribute('href', /docs\.typo3\.org/);
        await expect(learnMore).toHaveAttribute('target', '_blank');

        const help = await frame.locator('.passkey-banner-help').textContent();
        expect(help).toContain('administrator');
    });

    test('banner has "Set up now" and "Dismiss" buttons', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const banner = frame.locator('.passkey-setup-banner');
        await expect(banner.locator('.btn-primary')).toBeVisible();
        await expect(banner.locator('.btn-default')).toBeVisible();
    });

    test('dismiss button removes the banner from DOM', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const banner = frame.locator('.passkey-setup-banner');
        const dismissBtn = banner.locator('.btn-default');
        await dismissBtn.click();

        await expect(banner).not.toBeVisible({ timeout: 3000 });
    });

    test('dismiss persists to sessionStorage keyed to nudgeUntil', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        const banner = frame.locator('.passkey-setup-banner');
        await banner.locator('.btn-default').click();
        await expect(banner).not.toBeVisible({ timeout: 3000 });

        // Check sessionStorage was set
        const dismissed = await frame.evaluate(() =>
            sessionStorage.getItem('nr-passkeys-banner-dismissed'),
        );
        expect(dismissed).toBe('0');
    });

    test('banner does not reappear after dismiss on same page', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found, frame } = await findBanner(page);
        expect(found).toBe(true);

        // Dismiss
        await frame.locator('.passkey-setup-banner .btn-default').click();

        // Navigate away and back
        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        // Banner should NOT reappear (sessionStorage persists)
        const { found: foundAgain } = await findBanner(page);
        expect(foundAgain).toBe(false);
    });
});

test.describe('Passkey Banner - No enforcement', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
        if (!isLoggedIn) return;

        // Mock enforcement API to say banner is NOT needed
        await page.route('**/ajax/passkeys/enforcement**', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ requiresBanner: false }),
            });
        });
        await page.evaluate(() => sessionStorage.removeItem('nr-passkeys-banner-dismissed'));
    });

    test('banner does not render when requiresBanner is false', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const { found } = await findBanner(page);
        expect(found).toBe(false);
    });
});
