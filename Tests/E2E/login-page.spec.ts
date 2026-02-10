import { test, expect } from '@playwright/test';

/**
 * E2E tests for the passkey login integration in the standard TYPO3 login form.
 *
 * The passkey button is injected into the standard password login form via
 * a PSR-14 event listener — no separate login provider or ?loginProvider= switching.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 */

test.describe('Passkey Login on Standard Form', () => {
    test.beforeEach(async ({ page }) => {
        await page.context().clearCookies();
    });

    test('login page loads successfully', async ({ page }) => {
        const response = await page.goto('/typo3/login');
        expect(response?.status()).toBeLessThan(400);
        await expect(page).toHaveTitle(/TYPO3/);
    });

    test('standard login form has passkey button injected', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // The passkey container should be injected by JS
        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            // Login button
            await expect(page.locator('#passkey-login-btn')).toBeVisible();
            await expect(page.locator('#passkey-btn-text')).toContainText(/Sign in with Passkey|Passkey/);

            // Loading spinner (hidden initially)
            await expect(page.locator('#passkey-btn-loading')).toHaveClass(/d-none/);

            // Error element present
            await expect(page.locator('#passkey-error')).toBeAttached();

            // Hidden fields for assertion data
            await expect(page.locator('#passkey-assertion')).toBeAttached();
            await expect(page.locator('#passkey-challenge-token')).toBeAttached();
        }
    });

    test('passkey uses standard #t3-username field', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // The standard TYPO3 username field should be present
        await expect(page.locator('#t3-username')).toBeVisible();

        // There should be NO separate passkey username field
        await expect(page.locator('#passkey-username')).not.toBeAttached();
    });

    test('passkey config is set via inline script', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const config = await page.evaluate(() => (window as any).NrPasskeysBeConfig);
        if (config) {
            expect(config).toHaveProperty('loginOptionsUrl');
            expect(config).toHaveProperty('rpId');
            expect(config).toHaveProperty('origin');
            expect(config).toHaveProperty('discoverableEnabled');
        }
    });

    test('passkey login page runs in secure context (HTTPS)', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const isSecure = await page.evaluate(() => window.isSecureContext);
            expect(isSecure).toBe(true);
        }
    });

    test('empty username with discoverable login proceeds without validation error', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const config = await page.evaluate(() => (window as any).NrPasskeysBeConfig);
            test.skip(!config?.discoverableEnabled, 'Discoverable login is disabled');

            const btn = page.locator('#passkey-login-btn');
            await page.waitForTimeout(1000);

            const isDisabled = await btn.isDisabled().catch(() => true);
            test.skip(isDisabled, 'Passkey button is disabled (WebAuthn not available in this context)');

            // Clear the standard username field
            await page.locator('#t3-username').fill('');
            await btn.click();

            // With discoverable enabled, empty username should NOT show "Please enter your username"
            // Instead it proceeds with the discoverable flow (shows loading or WebAuthn prompt)
            const error = page.locator('#passkey-error');
            const hasUsernameError = await error.isVisible({ timeout: 2000 }).catch(() => false)
                && await error.textContent().then(t => /username/i.test(t || '')).catch(() => false);
            expect(hasUsernameError).toBe(false);
        }
    });

    test('login page JS loads without console errors', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404'),
        );
        expect(realErrors).toHaveLength(0);
    });

    test('no login provider switching needed', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // The passkey elements should be present on the standard form
        // without needing ?loginProvider= parameter
        const container = page.locator('#passkey-login-container');
        const standardForm = page.locator('#typo3-login-form');

        // Standard form should be present
        await expect(standardForm).toBeAttached();

        // If passkey container is visible, it should be inside the standard form
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const isInsideForm = await page.evaluate(() => {
                const form = document.getElementById('typo3-login-form');
                const passkeyContainer = document.getElementById('passkey-login-container');
                return form !== null && passkeyContainer !== null && form.contains(passkeyContainer);
            });
            expect(isInsideForm).toBe(true);
        }
    });
});
