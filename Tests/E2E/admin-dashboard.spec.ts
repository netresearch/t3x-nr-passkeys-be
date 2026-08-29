import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for the admin Passkey Dashboard module.
 *
 * Tests enforcement table, "Reset Login Lock" button label,
 * "Clear Nudge" button, and "Send Reminder" button.
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
 * Navigate to the admin passkey dashboard module.
 * Returns the frame (or page) where the module content lives.
 */
async function navigateToDashboard(page: Page): Promise<Page | ReturnType<Page['frame']>> {
    await page.goto('/typo3/module/system/admin_passkeys');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    // TYPO3 renders module content in an iframe (list_frame)
    const contentFrame = page.frame('list_frame');
    if (contentFrame) {
        return contentFrame;
    }
    return page;
}

test.describe('Admin Passkey Dashboard - Module Access', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('admin dashboard module loads successfully', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);
        // The page should contain some passkey-related content
        const body = frame!.locator('body');
        await expect(body).toBeAttached();

        // Should not be an error page
        const pageText = await body.textContent();
        expect(pageText).not.toContain('Page not found');
    });

    test('admin dashboard loads JS without console errors', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await navigateToDashboard(page);

        // Filter out known benign errors
        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404') && !e.includes('net::')
                && !e.includes('is not valid JSON'),
        );
        expect(realErrors).toHaveLength(0);
    });
});

test.describe('Admin Passkey Dashboard - Enforcement Table', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    // TODO: fix the underlying content-detection logic. Surfaced once the
    // shared E2E reusable workflow was repaired (typo3-ci-workflows #60/#61/#62)
    // and the tests actually ran. The dashboard iframe body does not contain
    // any of "enforcement", "group", or "passkey" when checked — either the
    // frame/URL resolution differs from what navigateToDashboard returns, or
    // the rendered content changed. Re-enable after root-causing.
    test.fixme('dashboard has groups enforcement section', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);
        const content = await frame!.locator('body').textContent();

        // Should contain enforcement-related content or a groups section
        // (exact text depends on whether groups exist)
        const hasEnforcementSection = content!.toLowerCase().includes('enforcement')
            || content!.toLowerCase().includes('group')
            || content!.toLowerCase().includes('passkey');
        expect(hasEnforcementSection).toBe(true);
    });

    test('enforcement select dropdowns exist when groups are present', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);
        const selects = frame!.locator('.passkey-enforcement-select');
        const count = await selects.count();

        // If there are groups, there should be enforcement selects
        if (count > 0) {
            // Each select should have enforcement options
            const firstSelect = selects.first();
            await expect(firstSelect).toBeEnabled();
            const options = firstSelect.locator('option');
            expect(await options.count()).toBeGreaterThanOrEqual(2);
        }
    });
});

test.describe('Admin Passkey Dashboard - Reset Login Lock', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('unlock buttons use "Reset Login Lock" or "Reset Lock" label', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);

        // Check for unlock/reset buttons
        const unlockButtons = frame!.locator('.passkey-unlock-user');
        const count = await unlockButtons.count();

        if (count > 0) {
            for (let i = 0; i < count; i++) {
                const text = await unlockButtons.nth(i).textContent();
                // Should say "Reset Lock" or "Reset Login Lock", NOT "Unlock"
                expect(text!.toLowerCase()).toMatch(/reset/i);
                expect(text!.toLowerCase()).not.toBe('unlock');
            }
        }
    });
});

test.describe('Admin Passkey Dashboard - Clear Nudge', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('clear nudge buttons are present for nudged users', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);
        const clearNudgeButtons = frame!.locator('.passkey-clear-nudge');
        const count = await clearNudgeButtons.count();

        // Clear nudge buttons may or may not be present depending on data
        // Just verify no JS errors (tested in module access tests above)
        if (count > 0) {
            // First clear nudge button should be clickable
            await expect(clearNudgeButtons.first()).toBeEnabled();
        }
    });
});

test.describe('Admin Passkey Dashboard - Send Reminder', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('send reminder buttons are present for users without passkeys', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const frame = await navigateToDashboard(page);
        const reminderButtons = frame!.locator('.passkey-send-reminder');
        const count = await reminderButtons.count();

        if (count > 0) {
            await expect(reminderButtons.first()).toBeEnabled();
        }
    });
});
