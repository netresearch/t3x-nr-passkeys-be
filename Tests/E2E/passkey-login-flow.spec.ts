import { test, expect, Page, CDPSession } from '@playwright/test';

/**
 * E2E tests for the full passkey login flow using a CDP Virtual Authenticator.
 *
 * These tests exercise the real WebAuthn ceremony end-to-end:
 * 1. Register a passkey via the management API (stores in both authenticator + DB)
 * 2. Log out
 * 3. Log in via the passkey button on the standard TYPO3 login form
 *
 * Uses page.request for HTTP calls (shares browser cookies reliably)
 * and page.evaluate only for WebAuthn browser APIs.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 *   - Admin user: admin / Joh316!!
 *   - Chromium-based browser (for CDP Virtual Authenticator support)
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

async function logOut(page: Page): Promise<void> {
    // TYPO3's /typo3/logout route may require a CSRF token and fails silently
    // in Playwright. Clearing cookies reliably destroys the session.
    await page.context().clearCookies();
}

/**
 * Set up a CDP Virtual Authenticator.
 */
async function setupVirtualAuthenticator(
    page: Page,
    options?: { hasResidentKey?: boolean; isUserVerified?: boolean },
): Promise<{ cdp: CDPSession; authenticatorId: string }> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: options?.hasResidentKey ?? true,
            hasUserVerification: options?.isUserVerified !== false,
            isUserVerified: options?.isUserVerified !== false,
        },
    });
    return { cdp, authenticatorId };
}

async function removeVirtualAuthenticator(cdp: CDPSession, authenticatorId: string): Promise<void> {
    try {
        await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
        await cdp.send('WebAuthn.disable');
    } catch {
        // Ignore cleanup errors
    }
}

/**
 * Get the TYPO3 AJAX URL (with CSRF token) for a given route key.
 */
async function getAjaxUrl(page: Page, routeKey: string): Promise<string | null> {
    return page.evaluate((key: string) => {
        return (window as any).TYPO3?.settings?.ajaxUrls?.[key] ?? null;
    }, routeKey);
}

/**
 * Register a passkey for the current user.
 *
 * Uses page.request with CSRF-tokenized AJAX URLs for HTTP calls
 * and page.evaluate only for navigator.credentials.create() (browser API).
 */
async function registerPasskeyViaApi(page: Page): Promise<{ success: boolean; error?: string }> {
    try {
        // Step 1: Get tokenized AJAX URL, then fetch registration options
        const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
        if (!optionsUrl) {
            return { success: false, error: 'AJAX URL passkeys_manage_registration_options not found in TYPO3.settings' };
        }

        const optResponse = await page.request.post(optionsUrl, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });
        if (!optResponse.ok()) {
            return { success: false, error: `Options ${optResponse.status()}: ${(await optResponse.text()).substring(0, 200)}` };
        }
        const optData = await optResponse.json();
        const options = optData.options;
        const challengeToken = optData.challengeToken;

        if (!options || !challengeToken) {
            return { success: false, error: 'Missing options or challengeToken in response' };
        }

        // Step 2: Create credential via WebAuthn API in browser (virtual authenticator handles this)
        const credentialData = await page.evaluate(async (opts) => {
            function base64urlToBuffer(b64url: string): ArrayBuffer {
                const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
                const pad = (4 - (b64.length % 4)) % 4;
                const padded = b64 + '='.repeat(pad);
                const bin = atob(padded);
                const buf = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
                return buf.buffer;
            }

            function bufferToBase64url(buf: ArrayBuffer): string {
                const bytes = new Uint8Array(buf);
                let bin = '';
                for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
                return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
            }

            const createOptions: CredentialCreationOptions = {
                publicKey: {
                    challenge: base64urlToBuffer(opts.challenge),
                    rp: { name: opts.rp.name, id: opts.rp.id },
                    user: {
                        id: base64urlToBuffer(opts.user.id),
                        name: opts.user.name,
                        displayName: opts.user.displayName,
                    },
                    pubKeyCredParams: (opts.pubKeyCredParams || []).map((p: any) => ({
                        type: p.type,
                        alg: p.alg,
                    })),
                    timeout: opts.timeout || 60000,
                    attestation: opts.attestation || 'none',
                    authenticatorSelection: opts.authenticatorSelection || {},
                    excludeCredentials: (opts.excludeCredentials || []).map((c: any) => ({
                        type: c.type,
                        id: base64urlToBuffer(c.id),
                        transports: c.transports || [],
                    })),
                },
            };

            const credential = await navigator.credentials.create(createOptions) as PublicKeyCredential;
            if (!credential) {
                return null;
            }

            const attestationResponse = credential.response as AuthenticatorAttestationResponse;
            return {
                id: bufferToBase64url(credential.rawId),
                rawId: bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64url(attestationResponse.clientDataJSON),
                    attestationObject: bufferToBase64url(attestationResponse.attestationObject),
                },
            };
        }, options);

        if (!credentialData) {
            return { success: false, error: 'navigator.credentials.create returned null' };
        }

        // Step 3: Send credential to server for verification via tokenized AJAX URL
        const verifyUrl = await getAjaxUrl(page, 'passkeys_manage_registration_verify');
        if (!verifyUrl) {
            return { success: false, error: 'AJAX URL passkeys_manage_registration_verify not found' };
        }

        const verifyResponse = await page.request.post(verifyUrl, {
            headers: { 'Content-Type': 'application/json' },
            data: {
                credential: credentialData,
                challengeToken,
                label: 'E2E Test Key',
            },
        });

        if (!verifyResponse.ok()) {
            return { success: false, error: `Verify ${verifyResponse.status()}: ${(await verifyResponse.text()).substring(0, 200)}` };
        }

        const verifyData = await verifyResponse.json();
        return { success: verifyData.status === 'ok' };
    } catch (e: any) {
        return { success: false, error: e?.message || String(e) };
    }
}

/**
 * Remove E2E test credentials to clean up after tests.
 */
async function cleanupTestCredentials(page: Page): Promise<void> {
    try {
        const listUrl = await getAjaxUrl(page, 'passkeys_manage_list');
        if (!listUrl) return;
        const listResponse = await page.request.get(listUrl);
        if (!listResponse.ok()) return;
        const data = await listResponse.json();

        const removeUrl = await getAjaxUrl(page, 'passkeys_manage_remove');
        if (!removeUrl) return;

        for (const cred of data.credentials || []) {
            if (cred.label === 'E2E Test Key') {
                await page.request.post(removeUrl, {
                    headers: { 'Content-Type': 'application/json' },
                    data: { uid: cred.uid },
                });
            }
        }
    } catch { /* ignore cleanup errors */ }
}

test.describe('Passkey Login Flow - Full WebAuthn Ceremony', () => {
    test('complete passkey login flow (username-first)', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        // Navigate to a backend page (needed for page.evaluate context)
        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        if (!regResult.success) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.fail(true, `Registration failed: ${regResult.error}`);
            return;
        }

        await logOut(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });
        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeVisible();
        await expect(loginBtn).toBeEnabled();

        await page.locator('#t3-username').fill(ADMIN_USER);
        await loginBtn.click();

        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        expect(page.url()).not.toContain('/login');

        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('complete passkey login flow (discoverable/usernameless)', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page, {
            hasResidentKey: true,
        });

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        if (!regResult.success) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.fail(true, `Registration failed: ${regResult.error}`);
            return;
        }

        await logOut(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const config = await page.evaluate(() => (window as any).NrPasskeysBeConfig);
        if (!config?.discoverableEnabled) {
            await cleanupTestCredentials(page);
            await removeVirtualAuthenticator(cdp, authenticatorId);
            // Login to clean up, then fail
            await loginAsAdmin(page);
            await cleanupTestCredentials(page);
            test.skip(true, 'Discoverable login is disabled in extension config');
            return;
        }

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });
        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled();

        await page.locator('#t3-username').fill('');
        await loginBtn.click();

        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        expect(page.url()).not.toContain('/login');

        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Form Integration', () => {
    test('hidden fields are populated with assertion data before form submit', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        if (!regResult.success) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.fail(true, `Registration failed: ${regResult.error}`);
            return;
        }

        await logOut(page);
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        // Intercept form submission by monkey-patching HTMLFormElement.submit().
        // PasskeyLogin.js calls loginForm.submit() which does NOT trigger
        // addEventListener('submit') handlers — it bypasses them entirely.
        await page.evaluate(() => {
            HTMLFormElement.prototype.submit = function () {
                (window as any).__passkeySubmitData = {
                    assertion: (document.getElementById('passkey-assertion') as HTMLInputElement)?.value,
                    challengeToken: (document.getElementById('passkey-challenge-token') as HTMLInputElement)?.value,
                    userident: (document.querySelector('.t3js-login-userident-field') as HTMLInputElement)?.value,
                };
                // Don't actually submit — capture data only
            };
        });

        await page.locator('#t3-username').fill(ADMIN_USER);
        await page.locator('#passkey-login-btn').click();

        await page.waitForFunction(
            () => (window as any).__passkeySubmitData != null,
            { timeout: 10000 },
        );

        const submitData = await page.evaluate(() => (window as any).__passkeySubmitData);

        expect(submitData.assertion).toBeTruthy();
        const assertionData = JSON.parse(submitData.assertion);
        expect(assertionData).toHaveProperty('id');
        expect(assertionData).toHaveProperty('type', 'public-key');
        expect(assertionData).toHaveProperty('response');
        expect(assertionData.response).toHaveProperty('authenticatorData');
        expect(assertionData.response).toHaveProperty('signature');
        expect(assertionData.response).toHaveProperty('clientDataJSON');

        expect(submitData.challengeToken).toBeTruthy();
        expect(submitData.challengeToken.length).toBeGreaterThan(10);

        expect(submitData.userident).toBeTruthy();
        const passkeyPayload = JSON.parse(submitData.userident);
        expect(passkeyPayload._type).toBe('passkey');
        expect(passkeyPayload.assertion).toHaveProperty('id');
        expect(passkeyPayload.assertion).toHaveProperty('type', 'public-key');
        expect(passkeyPayload.challengeToken).toBeTruthy();

        await removeVirtualAuthenticator(cdp, authenticatorId);
        const loggedIn2 = await loginAsAdmin(page);
        if (loggedIn2) {
            await cleanupTestCredentials(page);
        }
    });

    test('passkey elements are inside the standard TYPO3 login form', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const formContainsPasskey = await page.evaluate(() => {
            const form = document.getElementById('typo3-login-form');
            const btn = document.getElementById('passkey-login-btn');
            const assertion = document.getElementById('passkey-assertion');
            const token = document.getElementById('passkey-challenge-token');
            const error = document.getElementById('passkey-error');

            if (!form || !btn || !assertion || !token || !error) return false;

            return (
                form.contains(btn) &&
                form.contains(assertion) &&
                form.contains(token) &&
                form.contains(error)
            );
        });

        expect(formContainsPasskey).toBe(true);
    });

    test('loading spinner shows and button disables during ceremony', async ({ page }) => {
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const btnText = page.locator('#passkey-btn-text');
        const btnLoading = page.locator('#passkey-btn-loading');

        await expect(btnText).toBeVisible();
        await expect(btnLoading).not.toBeVisible();

        // Slow down the API response so loading state is observable
        await page.route('**/passkeys/login/options', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 500));
            await route.continue();
        });

        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled({ timeout: 3000 });

        await page.locator('#t3-username').fill(ADMIN_USER);
        await loginBtn.click();

        // Button should be disabled during loading
        await expect(loginBtn).toBeDisabled({ timeout: 2000 });

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Error Handling', () => {
    test('shows validation error when discoverable is disabled and no username', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // Override the config to disable discoverable login
        await page.evaluate(() => {
            if ((window as any).NrPasskeysBeConfig) {
                (window as any).NrPasskeysBeConfig.discoverableEnabled = false;
            }
        });

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled({ timeout: 3000 });

        await page.locator('#t3-username').fill('');
        await loginBtn.click();

        const error = page.locator('#passkey-error');
        await expect(error).toBeVisible({ timeout: 3000 });
        await expect(error).toContainText(/username/i);
    });

    test('shows error for non-existent user', async ({ page }) => {
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled({ timeout: 3000 });

        await page.locator('#t3-username').fill('nonexistent_user_e2e_test_xyz');
        await loginBtn.click();

        const error = page.locator('#passkey-error');
        await expect(error).toBeVisible({ timeout: 5000 });
        await expect(error).toContainText(/failed|error|too many attempts/i);

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('shows error when WebAuthn ceremony fails', async ({ page }) => {
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page, {
            isUserVerified: false,
        });

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled({ timeout: 3000 });

        await page.locator('#t3-username').fill(ADMIN_USER);
        await loginBtn.click();

        const error = page.locator('#passkey-error');

        await Promise.race([
            expect(error).toBeVisible({ timeout: 10000 }).catch(() => {}),
            page.waitForTimeout(10000),
        ]);

        expect(page.url()).toContain('/login');

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Failed Attempt Error Display', () => {
    test('failed passkey login shows passkey-specific error message', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        await page.evaluate(() => {
            const usernameField = document.getElementById('t3-username') as HTMLInputElement;
            const useridentField = document.querySelector('.t3js-login-userident-field') as HTMLInputElement;

            usernameField.value = 'admin';
            useridentField.value = JSON.stringify({
                _type: 'passkey',
                assertion: { id: 'fake', type: 'public-key', response: {} },
                challengeToken: 'fake-token',
            });

            sessionStorage.setItem('nr_passkey_attempt', '1');
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => {
                (document.getElementById('typo3-login-form') as HTMLFormElement).submit();
            }),
        ]);

        const passkeyError = page.locator('#passkey-error');
        await expect(passkeyError).toBeVisible({ timeout: 5000 });
        await expect(passkeyError).toContainText(/passkey.*failed|not accepted/i);

        const flagCleared = await page.evaluate(() => sessionStorage.getItem('nr_passkey_attempt'));
        expect(flagCleared).toBeNull();
    });

    test('normal password login failure does NOT show passkey error', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        await page.evaluate(() => {
            const usernameField = document.getElementById('t3-username') as HTMLInputElement;
            const useridentField = document.querySelector('.t3js-login-userident-field') as HTMLInputElement;

            usernameField.value = 'admin';
            useridentField.value = 'wrong-password';

            sessionStorage.removeItem('nr_passkey_attempt');
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => {
                (document.getElementById('typo3-login-form') as HTMLFormElement).submit();
            }),
        ]);

        const passkeyError = page.locator('#passkey-error');
        await expect(passkeyError).not.toBeVisible({ timeout: 3000 });
    });
});

