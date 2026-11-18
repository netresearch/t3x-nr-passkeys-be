/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { test, expect, loginAsAdmin, ADMIN_PASS } from './fixtures';

test.describe('Admin passkey dashboard', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('opens the passkey dashboard and loads its module', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));

        await page.goto('/typo3/module/admin/passkeys');
        const dashboard = page.frameLocator('#typo3-contentIframe').locator('#passkey-dashboard');
        await expect(dashboard).toBeVisible();
        await expect(dashboard.locator('#passkey-groups-table')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('persists the enforcement change on the test group', async ({ page }) => {
        await page.goto('/typo3/module/admin/passkeys');
        const dashboard = page.frameLocator('#typo3-contentIframe').locator('#passkey-dashboard');
        const row = dashboard.getByRole('row').filter({ hasText: 'E2E passkey enforcement' });
        const select = row.getByRole('combobox');
        await expect(select).toBeEnabled();
        await expect(select.locator('option')).toHaveCount(4);
        const initial = await select.inputValue();
        const next = initial === 'off' ? 'encourage' : 'off';

        async function setEnforcement(value: string) {
            const responseForUpdate = () => page.waitForResponse(response =>
                new URL(response.url()).pathname.endsWith('/passkeys/admin/update-enforcement')
                && response.request().method() === 'POST',
            );
            const pending = responseForUpdate();
            await select.selectOption(value);
            let response = await pending;
            if (response.status() === 422) {
                const password = page.locator('#verify-sudo-mode input[name="password"]');
                await password.fill(ADMIN_PASS);
                const retry = responseForUpdate();
                await password.press('Enter');
                response = await retry;
            }
            expect(response.status()).toBe(200);
            expect(await response.json()).toMatchObject({ status: 'ok', enforcement: value });
        }

        try {
            await setEnforcement(next);
            await page.reload();
            await expect(select).toHaveValue(next);
        } finally {
            if (await select.inputValue() !== initial) {
                await setEnforcement(initial);
            }
        }
    });

    test('offers reminder, clear reminder and reset login lock for the nudged test user', async ({ page }) => {
        await page.goto('/typo3/module/admin/passkeys');
        const dashboard = page.frameLocator('#typo3-contentIframe').locator('#passkey-dashboard');
        const row = dashboard.getByRole('row').filter({ hasText: 'e2e_passkey_reminder' });
        await expect(row.locator('.passkey-send-reminder')).toBeEnabled();
        await expect(row.locator('.passkey-clear-nudge')).toBeEnabled();
        const reset = row.locator('.passkey-unlock-user');
        await expect(reset).toBeEnabled();
        await expect(reset).toHaveAccessibleName(/reset.*lock/i);
    });
});
