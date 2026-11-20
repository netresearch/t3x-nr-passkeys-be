/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { test, expect, loginAsAdmin, ADMIN_USER } from './fixtures';

// Documentation images are regenerated only when SCREENSHOT_DIR is set explicitly.
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '.Build/screenshots';

test.describe('Documentation Screenshots', () => {
    test('login page with passkey button', async ({ page }) => {
        await page.goto('/typo3/login');
        await expect(page.locator('#passkey-login-btn')).toBeVisible();
        await page.screenshot({ path: `${SCREENSHOT_DIR}/Login/LoginPageWithPasskey.png` });
    });

    test('login page with username filled', async ({ page }) => {
        await page.goto('/typo3/login');
        const username = page.locator('#t3-username');
        await username.fill(ADMIN_USER);
        await expect(username).toHaveValue(ADMIN_USER);
        await expect(page.locator('#passkey-login-btn')).toBeVisible();
        await page.screenshot({ path: `${SCREENSHOT_DIR}/Login/LoginPageUsernameFirst.png` });
    });

    test('login page passkey error message', async ({ page }) => {
        await page.addInitScript(() => sessionStorage.setItem('nr_passkey_attempt', String(Date.now())));
        await page.goto('/typo3/login');
        const error = page.locator('#passkey-error');
        await expect(error).toBeVisible();
        await expect(error).toContainText(/passkey/i);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/Login/LoginPagePasskeyError.png` });
    });

    test('user settings passkey management', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/typo3/module/user/setup');
        const frame = page.frameLocator('#typo3-contentIframe');
        await frame.getByRole('tab', { name: /security/i }).click();
        const panel = frame.locator('#passkey-management-container');
        await expect(panel.locator('#passkey-add-btn')).toBeVisible();
        await panel.scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${SCREENSHOT_DIR}/UserSettings/PasskeyManagement.png` });
    });
});
