/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
import { expect, type Page } from '@playwright/test';

export { test, expect } from '@playwright/test';

export const ADMIN_USER = process.env.TYPO3_ADMIN_USER || 'admin';
export const ADMIN_PASS = process.env.TYPO3_ADMIN_PASS || 'Joh316!!';

export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/typo3/login');
    await page.locator('input[name="username"]').fill(ADMIN_USER);
    await page.locator('input[name="p_field"]').fill(ADMIN_PASS);
    await page.locator('#t3-login-submit').click();
    await expect(page.locator('#typo3-contentIframe')).toBeAttached();
}
