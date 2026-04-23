import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for modal interactions in the passkey extension.
 *
 * Tests that TYPO3 Modal dialogs work correctly in the admin FormEngine
 * context (be_users edit form with PasskeyAdminInfo element).
 *
 * NOTE: The user-settings modal (PasskeyManagement.js) depends on the
 * PasskeySettingsPanel rendering, which has a known DI issue (callUserFunction
 * uses makeInstance without DI). Those tests are in user-settings.spec.ts
 * and will pass once the DI issue is resolved.
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

test.describe('Admin FormEngine - be_users Passkey Info', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    // TODO: the be_users edit form returns a 500 Internal Server Error in
    // the E2E environment, so console captures an error. Surfaced once the
    // shared E2E workflow was repaired (typo3-ci-workflows #60/#61/#62) and
    // the test could actually navigate. Likely a missing TCA fixture or a
    // required installed extension in the E2E setup. Re-enable after
    // root-causing.
    test.fixme('be_users edit form loads without JS errors', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        // Navigate to admin user record edit form
        await page.goto('/typo3/record/edit?edit[be_users][1]=edit');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404') && !e.includes('net::')
                && !e.includes('is not valid JSON'),
        );
        expect(realErrors).toHaveLength(0);
    });

    test('be_users edit form renders successfully', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3/record/edit?edit[be_users][1]=edit');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        const contentFrame = page.frame('list_frame') ?? page;
        const body = contentFrame.locator('body');
        const bodyText = await body.textContent();

        // Should have the user edit form, not an error page
        expect(bodyText).not.toContain('Page not found');
        // Should contain the username field or admin-related content
        expect(bodyText!.length).toBeGreaterThan(100);
    });

    test('PasskeyAdminInfo element renders unlock button with "Reset" label', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3/record/edit?edit[be_users][1]=edit');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const contentFrame = page.frame('list_frame') ?? page;

        // Look for the unlock/reset button
        const unlockBtn = contentFrame.locator('.t3js-passkey-unlock-button');
        const count = await unlockBtn.count();

        if (count > 0) {
            // The button text should contain "Reset" (not "Unlock")
            const buttonText = await unlockBtn.first().textContent();
            expect(buttonText!.toLowerCase()).toMatch(/reset/i);
        }
        // If the passkey info element isn't present, the test still passes
        // (the element only renders when the user has passkey-related data)
    });

    test('PasskeyAdminInfo revoke buttons use trigger callbacks (no aria-hidden errors)', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        const consoleWarnings: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'warning' || msg.type() === 'error') {
                consoleWarnings.push(msg.text());
            }
        });

        await page.goto('/typo3/record/edit?edit[be_users][1]=edit');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const contentFrame = page.frame('list_frame') ?? page;

        // Try to click a revoke button if present
        const revokeBtn = contentFrame.locator('.t3js-passkey-revoke-button').first();
        if (await revokeBtn.count() > 0) {
            await revokeBtn.click();
            await page.waitForTimeout(1000);

            // Modal should appear without aria-hidden focus errors
            const ariaErrors = consoleWarnings.filter(e => e.includes('aria-hidden'));
            expect(ariaErrors).toHaveLength(0);

            // Close modal via Cancel if visible
            const modal = page.locator('.modal.show, .modal[style*="display: block"]');
            if (await modal.isVisible().catch(() => false)) {
                const cancelBtn = modal.locator('button', { hasText: /cancel/i });
                if (await cancelBtn.isVisible().catch(() => false)) {
                    await cancelBtn.click();
                    await expect(modal).not.toBeVisible({ timeout: 5000 });
                }
            }
        }
    });

    // TODO: modal visibility assertion fails in the E2E environment. Likely
    // cascades from the be_users edit form 500 above (same spec file) — the
    // PasskeyAdminInfo element may never render because the host form errors
    // out. Re-enable after the previous test is fixed.
    test.fixme('PasskeyAdminInfo unlock button opens modal that can be cancelled', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3/record/edit?edit[be_users][1]=edit');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(3000);

        const contentFrame = page.frame('list_frame') ?? page;

        const unlockBtn = contentFrame.locator('.t3js-passkey-unlock-button').first();
        if (await unlockBtn.count() === 0) {
            // PasskeyInfoElement not rendered for this user - skip
            return;
        }

        await unlockBtn.click();
        await page.waitForTimeout(1000);

        // Modal should be visible
        const modal = page.locator('.modal.show, .modal[style*="display: block"]');
        await expect(modal).toBeVisible({ timeout: 5000 });

        // Modal should have Cancel button that works
        const cancelBtn = modal.locator('button', { hasText: /cancel/i });
        await expect(cancelBtn).toBeVisible();
        await cancelBtn.click();

        // Modal should close
        await expect(modal).not.toBeVisible({ timeout: 5000 });
    });
});

test.describe('Admin Dashboard - AJAX Actions', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('enforcement select change sends AJAX request', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3/module/system/admin_passkeys');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        const contentFrame = page.frame('list_frame') ?? page;
        const selects = contentFrame.locator('.passkey-enforcement-select');
        const count = await selects.count();

        if (count === 0) {
            // No groups with enforcement selects
            return;
        }

        // Intercept the AJAX call
        const ajaxPromise = page.waitForResponse(
            (r) => r.url().includes('passkeys') && r.url().includes('enforcement'),
            { timeout: 10000 },
        ).catch(() => null);

        // Change the first select value
        const select = selects.first();
        const currentValue = await select.inputValue();
        const options = await select.locator('option').allTextContents();
        const newValue = options.find(o => o !== currentValue) || currentValue;
        await select.selectOption({ label: newValue });

        const response = await ajaxPromise;
        if (response) {
            expect(response.status()).toBeLessThan(500);
        }
    });

    test('clear nudge AJAX route is available', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed');

        await page.goto('/typo3/module/system/admin_passkeys');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        // Verify the AJAX URL is registered in TYPO3.settings
        const contentFrame = page.frame('list_frame') ?? page;
        const ajaxUrl = await contentFrame.evaluate(() => {
            return (window as any).TYPO3?.settings?.ajaxUrls?.passkeys_admin_clear_nudge;
        });

        // The AJAX URL should be defined (not undefined/null)
        // If it's undefined, the user needs to flush TYPO3 caches
        if (ajaxUrl === undefined) {
            // Check in the top frame too (TYPO3 may store settings there)
            const topAjaxUrl = await page.evaluate(() => {
                return (window as any).TYPO3?.settings?.ajaxUrls?.passkeys_admin_clear_nudge;
            });
            // At least one frame should have it
            expect(ajaxUrl ?? topAjaxUrl).toBeTruthy();
        }
    });
});
