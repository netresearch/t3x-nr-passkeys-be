import { Page, CDPSession } from '@playwright/test';
import { test, expect } from './fixtures';

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
 *   - TYPO3 instance running (via `./Build/Scripts/runTests.sh -s e2e` or TYPO3_BASE_URL)
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
    // in Playwright, so the session is ended by dropping its cookies.
    await page.context().clearCookies();
    const afterClear = (await page.context().cookies()).map((c) => c.name).join(', ') || 'none';

    // And then checked, because a surviving session is invisible here and shows
    // up several steps later as a missing element on a page the test believes
    // is the login screen: /typo3/login redirects an authenticated user
    // straight back into the backend.
    await page.goto('/typo3/login');
    await page.waitForLoadState('networkidle');
    const usernameInput = page.locator('input[name="username"]');
    if (!await usernameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        const afterGoto = (await page.context().cookies()).map((c) => c.name).join(', ') || 'none';
        throw new Error(
            `Log out did not end the session: still at ${page.url()}; `
            + `cookies after clear [${afterClear}], after navigation [${afterGoto}]`,
        );
    }
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

/**
 * Turn the authenticator's automatic user-presence simulation on or off.
 *
 * With it on — the default — the virtual authenticator approves every ceremony
 * the moment it is asked, including the conditional (autofill) one that
 * PasskeyLogin.js arms on the login page whenever discoverable login is
 * enabled. Once a resident credential exists, merely opening /typo3/login then
 * logs the browser straight into the backend: measured as
 * POST /passkeys/login/options, POST /passkeys/login/verify,
 * POST /typo3/login?loginProvider=… before any test code runs. That is the
 * feature working, and it makes every assertion about the login page
 * unreachable. A human sees a prompt here and has to choose; these tests switch
 * the simulation off for that stretch and back on for the ceremony they drive
 * themselves.
 */
async function setAutomaticPresence(cdp: CDPSession, authenticatorId: string, enabled: boolean): Promise<void> {
    await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled });
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

        let optResponse = await page.request.post(optionsUrl, {
            headers: { 'Content-Type': 'application/json' },
            data: {},
        });

        // Enrolling a credential is a Sudo Mode route (see
        // Configuration/Backend/AjaxRoutes.php): TYPO3 answers the first call
        // with 422 and the URI that takes the step-up. A real user types their
        // password into the modal at this point, so the test does the same and
        // repeats the request.
        if (optResponse.status() === 422) {
            const initialization = (await optResponse.json()).sudoModeInitialization;
            if (!initialization?.verifyActionUri) {
                return { success: false, error: '422 without sudoModeInitialization.verifyActionUri' };
            }
            const verified = await page.request.post(initialization.verifyActionUri, {
                form: { password: ADMIN_PASS },
            });
            if (!verified.ok()) {
                return { success: false, error: `Sudo-mode step-up ${verified.status()}: ${(await verified.text()).substring(0, 200)}` };
            }
            optResponse = await page.request.post(optionsUrl, {
                headers: { 'Content-Type': 'application/json' },
                data: {},
            });
        }

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

// These three register a real passkey through the AJAX API, then drive the
// login ceremony with the CDP virtual authenticator. The rpId mismatch that
// once made registration throw `SecurityError` in the containerised
// environment is gone: `E2E_SECURE_ALIAS_HOST` in Build/Scripts/runTests.conf
// gives the instance a host the browser accepts as a secure context.
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
        }
        // A registration that does not work is this test's subject failing, not
        // a reason to pass: the ceremony below has nothing to authenticate with.
        expect(regResult.success, `Registration failed: ${regResult.error}`).toBe(true);

        // A resident credential now exists, so the conditional ceremony would
        // log the browser back in the moment the login page loads.
        await setAutomaticPresence(cdp, authenticatorId, false);

        await logOut(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });
        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeVisible();
        await expect(loginBtn).toBeEnabled();

        await page.locator('#t3-username').fill(ADMIN_USER);
        // The click is this test's ceremony, so the authenticator answers again.
        await setAutomaticPresence(cdp, authenticatorId, true);
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
        }
        // A registration that does not work is this test's subject failing, not
        // a reason to pass: the ceremony below has nothing to authenticate with.
        expect(regResult.success, `Registration failed: ${regResult.error}`).toBe(true);

        // A resident credential now exists, so the conditional ceremony would
        // log the browser back in the moment the login page loads.
        await setAutomaticPresence(cdp, authenticatorId, false);

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
        // The click is this test's ceremony, so the authenticator answers again.
        await setAutomaticPresence(cdp, authenticatorId, true);
        await loginBtn.click();

        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        expect(page.url()).not.toContain('/login');

        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Form Integration', () => {
    test('the login form carries the verified login token before submit', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        if (!regResult.success) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
        }
        // A registration that does not work is this test's subject failing, not
        // a reason to pass: the ceremony below has nothing to authenticate with.
        expect(regResult.success, `Registration failed: ${regResult.error}`).toBe(true);

        // A resident credential now exists, so the conditional ceremony would
        // log the browser back in the moment the login page loads.
        await setAutomaticPresence(cdp, authenticatorId, false);

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
        // The click is this test's ceremony, so the authenticator answers again.
        await setAutomaticPresence(cdp, authenticatorId, true);
        await page.locator('#passkey-login-btn').click();

        await page.waitForFunction(
            () => (window as any).__passkeySubmitData != null,
            { timeout: 10000 },
        );

        const submitData = await page.evaluate(() => (window as any).__passkeySubmitData);

        // What the form carries is the single-use login token, not the
        // assertion: PasskeyLogin.js sends the assertion to
        // /passkeys/login/verify, and that endpoint answers with a token which
        // submitLoginToken() packs into userident. The raw-assertion payload
        // this test asserted before belongs to submitAssertion(), the fallback
        // for an installation where no verify URL is injected.
        expect(submitData.userident).toBeTruthy();
        const passkeyPayload = JSON.parse(submitData.userident);
        expect(passkeyPayload._type).toBe('passkey_token');
        expect(typeof passkeyPayload.token).toBe('string');
        expect(passkeyPayload.token.length).toBeGreaterThan(10);

        // And the assertion stays out of the form on this path. A regression
        // that puts it back would replay a ceremony the server already consumed.
        expect(submitData.assertion).toBe('');

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
        // An unknown username gets decoy credentials the authenticator does not
        // hold, so the ceremony runs until the timeout the server puts into the
        // options — `timeout: 60000` in AssertionService — before the browser
        // rejects it. That is longer than the 30 s default in
        // playwright.config.ts, so this one test needs its own budget. The CDP
        // virtual authenticator sometimes rejects in well under a second and
        // sometimes waits the full minute; both stay inside this budget.
        test.setTimeout(90_000);

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled({ timeout: 3000 });

        await page.locator('#t3-username').fill('nonexistent_user_e2e_test_xyz');
        await loginBtn.click();

        // Wait for the ceremony to return, not for a fixed number of seconds.
        // PasskeyLogin.js calls setLoading(false) in the same catch block that
        // then calls handleAuthError, so the button leaving its loading state is
        // the event the message depends on. The budget covers the server's own
        // 60 s ceremony timeout with room to spare.
        await expect(loginBtn).toBeEnabled({ timeout: 75_000 });

        const error = page.locator('#passkey-error');
        await expect(error).toBeVisible({ timeout: 5000 });
        // For an unknown user the server answers with decoy options instead of
        // saying so, which is the point — a caller cannot tell existing
        // usernames from invented ones. The ceremony therefore fails in the
        // browser the same way a cancelled one does, and that is the message
        // the user sees.
        await expect(error).toContainText(/failed|error|too many attempts|cancelled or no passkey found/i);

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

            // The marker carries the submission time. PasskeyLogin.js ignores a
            // legacy value without one — a bare '1', which is what this test
            // used to plant, so it was asserting against a path the module
            // deliberately does not take (see the vitest case "ignores a legacy
            // marker that carries no timestamp").
            sessionStorage.setItem('nr_passkey_attempt', String(Date.now()));
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

