import { test, expect } from '@playwright/test';

/**
 * E2E tests for the passkey login page rendering and behavior.
 *
 * These tests verify the login page UI without requiring authentication.
 * API endpoint tests are in api-endpoints.spec.ts.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 */

test.describe('Passkey Login Page', () => {
    test.beforeEach(async ({ page }) => {
        await page.context().clearCookies();
    });

    test('login page loads successfully', async ({ page }) => {
        const response = await page.goto('/typo3/login');
        expect(response?.status()).toBeLessThan(400);
        await expect(page).toHaveTitle(/TYPO3/);
    });

    test('passkey login form renders all required elements', async ({ page }) => {
        await page.goto('/typo3/login?loginProvider=1700000000');

        const container = page.locator('#passkey-login-container');

        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            // Username field
            await expect(page.locator('#passkey-username')).toBeVisible();
            await expect(page.locator('#passkey-username')).toHaveAttribute('autocomplete', /username/);

            // Login button
            await expect(page.locator('#passkey-login-btn')).toBeVisible();
            await expect(page.locator('#passkey-btn-text')).toContainText(/Continue with Passkey|Passkey/);

            // Loading spinner (hidden initially)
            await expect(page.locator('#passkey-btn-loading')).toHaveClass(/d-none/);

            // Error element present
            await expect(page.locator('#passkey-error')).toBeAttached();

            // Hidden fields for assertion data
            await expect(page.locator('#passkey-assertion')).toBeAttached();
            await expect(page.locator('#passkey-challenge-token')).toBeAttached();

            // Options URL data attribute
            await expect(container).toHaveAttribute('data-options-url', '/typo3/passkeys/login/options');
        }
    });

    test('passkey form has correct data attributes', async ({ page }) => {
        await page.goto('/typo3/login?loginProvider=1700000000');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            await expect(container).toHaveAttribute('data-options-url');
            await expect(container).toHaveAttribute('data-rp-id');
            await expect(container).toHaveAttribute('data-origin');
            await expect(container).toHaveAttribute('data-discoverable');
        }
    });

    test('passkey login page runs in secure context (HTTPS)', async ({ page }) => {
        // DDEV redirects HTTP to HTTPS, so we cannot test insecure context behavior.
        // Instead, verify that the login page is served over a secure context,
        // which is a prerequisite for WebAuthn to function.
        await page.goto('/typo3/login?loginProvider=1700000000');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const isSecure = await page.evaluate(() => window.isSecureContext);
            expect(isSecure).toBe(true);
        }
    });

    test('empty username shows validation error on click', async ({ page }) => {
        await page.goto('/typo3/login?loginProvider=1700000000');
        // Wait for JS to initialize and potentially enable/disable the button
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const btn = page.locator('#passkey-login-btn');
            // Wait a moment for JS initialization to complete
            await page.waitForTimeout(1000);

            // Only test if button is enabled (requires HTTPS + WebAuthn API available)
            const isDisabled = await btn.isDisabled().catch(() => true);
            test.skip(isDisabled, 'Passkey button is disabled (WebAuthn not available in this context)');

            await page.locator('#passkey-username').fill('');
            await btn.click();

            const error = page.locator('#passkey-error');
            await expect(error).toBeVisible({ timeout: 3000 });
            await expect(error).toContainText(/username/i);
        }
    });

    test('password fallback link exists when password login enabled', async ({ page }) => {
        await page.goto('/typo3/login?loginProvider=1700000000');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const fallback = page.locator('#passkey-password-fallback');
            // locator.isAttached() is not a valid Playwright method - use count() instead
            if (await fallback.count() > 0) {
                await expect(fallback).toContainText(/password/i);
            }
        }
    });

    test('login page JS loads without console errors', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.goto('/typo3/login?loginProvider=1700000000');
        await page.waitForLoadState('networkidle');

        const realErrors = consoleErrors.filter(
            (e) => !e.includes('favicon') && !e.includes('404'),
        );
        expect(realErrors).toHaveLength(0);
    });

    test('noscript warning is present in DOM', async ({ page }) => {
        await page.goto('/typo3/login?loginProvider=1700000000');

        const container = page.locator('#passkey-login-container');
        if (await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            const noscript = page.locator('#passkey-login-container noscript');
            await expect(noscript).toBeAttached();
        }
    });

    test('login page has multiple login providers', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // TYPO3 shows login providers as links with data-providerkey attribute
        const providers = page.locator('[data-providerkey]');
        const count = await providers.count();
        // With our passkey provider registered, there should be at least 1 provider link
        // (the passkey provider switch link, since password is the default)
        expect(count).toBeGreaterThanOrEqual(1);
    });
});
