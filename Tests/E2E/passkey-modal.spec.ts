/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { test, expect, loginAsAdmin } from './fixtures';

test.describe('Admin FormEngine passkey controls', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
        const users = page.locator('a[data-moduleroute-identifier="backend_user_management"]');
        await page.goto(await users.evaluate(link => (link as HTMLAnchorElement).href));
        // The native edit link carries the TYPO3 route token.
        const frame = page.frameLocator('#typo3-contentIframe');
        await frame.getByRole('link', { name: /e2e_passkey_management/ }).click();
        await expect(frame.locator('.t3js-passkey-unlock-button')).toBeVisible();
        expect(await page.pageErrors()).toEqual([]);
    });

    test('opens and cancels the reset login lock confirmation', async ({ page }) => {
        const frame = page.frameLocator('#typo3-contentIframe');
        const reset = frame.locator('.t3js-passkey-unlock-button');
        await expect(reset).toHaveAccessibleName(/reset.*lock/i);
        await reset.click();
        const modal = page.locator('.t3js-modal');
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: /cancel/i }).click();
        await expect(modal).toBeHidden();
    });

    test('opens and cancels a credential revocation without hiding focused controls', async ({ page }) => {
        const warnings: string[] = [];
        page.on('console', msg => {
            if (msg.text().includes('aria-hidden') && ['warning', 'error'].includes(msg.type())) {
                warnings.push(msg.text());
            }
        });
        const frame = page.frameLocator('#typo3-contentIframe');
        const revoke = frame.locator('.t3js-passkey-revoke-button');
        await revoke.click();
        const modal = page.locator('.t3js-modal');
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: /cancel/i }).click();
        await expect(modal).toBeHidden();
        await expect(revoke).toBeEnabled();
        expect(warnings).toEqual([]);
    });
});

test('clear reminder route is available to the admin dashboard', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/typo3/module/admin/passkeys');
    const dashboard = page.frameLocator('#typo3-contentIframe').locator('#passkey-dashboard');
    await expect(dashboard).toBeVisible();
    const route = await dashboard.evaluate(() => (window as any).TYPO3.settings.ajaxUrls.passkeys_admin_clear_nudge);
    expect(route).toContain('/passkeys/admin/clear-nudge');
});
