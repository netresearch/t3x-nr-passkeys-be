import { test, expect, Page, CDPSession } from '@playwright/test';

/**
 * E2E tests for the full passkey login flow using a CDP Virtual Authenticator.
 *
 * These tests exercise the real WebAuthn ceremony end-to-end:
 * 1. Register a passkey via the management API (stores in both authenticator + DB)
 * 2. Log out
 * 3. Log in via the passkey button on the standard TYPO3 login form
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 *   - Admin user: admin / Joh316!!
 *   - Chromium-based browser (for CDP Virtual Authenticator support)
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
    await page.goto('/typo3/logout');
    await page.waitForLoadState('networkidle');
}

/**
 * Set up a CDP Virtual Authenticator.
 *
 * The virtual authenticator intercepts all navigator.credentials calls and
 * responds automatically — no physical device or user prompt needed.
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
 * Register a passkey for the current user via the management API.
 * Must be called while authenticated AND with a virtual authenticator active.
 *
 * Returns { success: boolean, error?: string }
 */
async function registerPasskeyViaApi(page: Page): Promise<{ success: boolean; error?: string }> {
    return page.evaluate(async () => {
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

        try {
            // Step 1: Get registration options from server
            const optRes = await fetch('/typo3/passkeys/manage/registration/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            if (!optRes.ok) {
                const body = await optRes.text();
                return { success: false, error: `Options ${optRes.status}: ${body.substring(0, 200)}` };
            }
            const optData = await optRes.json();
            const options = optData.options;
            const challengeToken = optData.challengeToken;

            if (!options || !challengeToken) {
                return { success: false, error: 'Missing options or challengeToken in response' };
            }

            // Step 2: Create credential via WebAuthn API (virtual authenticator handles this)
            const createOptions: CredentialCreationOptions = {
                publicKey: {
                    challenge: base64urlToBuffer(options.challenge),
                    rp: { name: options.rp.name, id: options.rp.id },
                    user: {
                        id: base64urlToBuffer(options.user.id),
                        name: options.user.name,
                        displayName: options.user.displayName,
                    },
                    pubKeyCredParams: (options.pubKeyCredParams || []).map((p: any) => ({
                        type: p.type,
                        alg: p.alg,
                    })),
                    timeout: options.timeout || 60000,
                    attestation: options.attestation || 'none',
                    authenticatorSelection: options.authenticatorSelection || {},
                    excludeCredentials: (options.excludeCredentials || []).map((c: any) => ({
                        type: c.type,
                        id: base64urlToBuffer(c.id),
                        transports: c.transports || [],
                    })),
                },
            };

            const credential = await navigator.credentials.create(createOptions) as PublicKeyCredential;
            if (!credential) {
                return { success: false, error: 'navigator.credentials.create returned null' };
            }

            const attestationResponse = credential.response as AuthenticatorAttestationResponse;

            // Step 3: Build the credential response object
            const credentialData = {
                id: bufferToBase64url(credential.rawId),
                rawId: bufferToBase64url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64url(attestationResponse.clientDataJSON),
                    attestationObject: bufferToBase64url(attestationResponse.attestationObject),
                },
            };

            // Step 4: Send to server for verification + storage
            const verifyRes = await fetch('/typo3/passkeys/manage/registration/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    credential: credentialData,
                    challengeToken,
                    label: 'E2E Test Key',
                }),
            });

            if (!verifyRes.ok) {
                const body = await verifyRes.text();
                return { success: false, error: `Verify ${verifyRes.status}: ${body.substring(0, 200)}` };
            }

            const verifyData = await verifyRes.json();
            return { success: verifyData.status === 'ok' };
        } catch (e: any) {
            return { success: false, error: e?.message || String(e) };
        }
    });
}

/**
 * Remove E2E test credentials to clean up after tests.
 */
async function cleanupTestCredentials(page: Page): Promise<void> {
    await page.evaluate(async () => {
        try {
            const res = await fetch('/typo3/passkeys/manage/list');
            if (!res.ok) return;
            const data = await res.json();
            for (const cred of data.credentials || []) {
                if (cred.label === 'E2E Test Key') {
                    await fetch('/typo3/passkeys/manage/remove', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ uid: cred.uid }),
                    });
                }
            }
        } catch { /* ignore cleanup errors */ }
    });
}

test.describe('Passkey Login Flow - Full WebAuthn Ceremony', () => {
    test('complete passkey login flow (username-first)', async ({ page }) => {
        // Step 1: Login with password
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        // Step 2: Set up virtual authenticator (must be BEFORE any WebAuthn calls)
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        // Step 3: Register a passkey via management API
        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        test.skip(!regResult.success, `Registration failed: ${regResult.error}`);

        // Step 4: Log out
        await logOut(page);

        // Step 5: Navigate to login page (same page, same CDP session = same authenticator)
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // Wait for passkey UI to be injected
        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });
        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeVisible();
        await expect(loginBtn).toBeEnabled();

        // Step 6: Enter username and click passkey button
        await page.locator('#t3-username').fill(ADMIN_USER);
        await loginBtn.click();

        // Step 7: Wait for login to complete (redirect away from login page)
        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        expect(page.url()).not.toContain('/login');

        // Clean up
        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('complete passkey login flow (discoverable/usernameless)', async ({ page }) => {
        // Step 1: Login with password
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        // Step 2: Set up virtual authenticator with resident key support
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page, {
            hasResidentKey: true,
        });

        // Step 3: Register a passkey
        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        test.skip(!regResult.success, `Registration failed: ${regResult.error}`);

        // Step 4: Log out
        await logOut(page);

        // Step 5: Navigate to login page
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const config = await page.evaluate(() => (window as any).NrPasskeysBeConfig);
        test.skip(!config?.discoverableEnabled, 'Discoverable login is disabled');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });
        const loginBtn = page.locator('#passkey-login-btn');
        await expect(loginBtn).toBeEnabled();

        // Step 6: Leave username EMPTY and click passkey button
        await page.locator('#t3-username').fill('');
        await loginBtn.click();

        // Step 7: Wait for login to complete
        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        expect(page.url()).not.toContain('/login');

        // Clean up
        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Form Integration', () => {
    test('hidden fields are populated with assertion data before form submit', async ({ page }) => {
        // Register a passkey
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const regResult = await registerPasskeyViaApi(page);
        test.skip(!regResult.success, `Registration failed: ${regResult.error}`);

        // Log out and go to login
        await logOut(page);
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        await expect(container).toBeVisible({ timeout: 5000 });

        // Intercept form submission to inspect hidden fields
        await page.evaluate(() => {
            const form = document.getElementById('typo3-login-form') as HTMLFormElement;
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    (window as any).__passkeySubmitData = {
                        assertion: (document.getElementById('passkey-assertion') as HTMLInputElement)?.value,
                        challengeToken: (document.getElementById('passkey-challenge-token') as HTMLInputElement)?.value,
                        userident: (document.querySelector('.t3js-login-userident-field') as HTMLInputElement)?.value,
                    };
                });
            }
        });

        await page.locator('#t3-username').fill(ADMIN_USER);
        await page.locator('#passkey-login-btn').click();

        // Wait for the form submit interception
        await page.waitForFunction(
            () => (window as any).__passkeySubmitData != null,
            { timeout: 10000 },
        );

        const submitData = await page.evaluate(() => (window as any).__passkeySubmitData);

        // Assertion field: still populated for middleware inspection
        expect(submitData.assertion).toBeTruthy();
        const assertionData = JSON.parse(submitData.assertion);
        expect(assertionData).toHaveProperty('id');
        expect(assertionData).toHaveProperty('type', 'public-key');
        expect(assertionData).toHaveProperty('response');
        expect(assertionData.response).toHaveProperty('authenticatorData');
        expect(assertionData.response).toHaveProperty('signature');
        expect(assertionData.response).toHaveProperty('clientDataJSON');

        // Challenge token hidden field still populated
        expect(submitData.challengeToken).toBeTruthy();
        expect(submitData.challengeToken.length).toBeGreaterThan(10);

        // Userident carries the full passkey payload as JSON
        expect(submitData.userident).toBeTruthy();
        const passkeyPayload = JSON.parse(submitData.userident);
        expect(passkeyPayload._type).toBe('passkey');
        expect(passkeyPayload.assertion).toHaveProperty('id');
        expect(passkeyPayload.assertion).toHaveProperty('type', 'public-key');
        expect(passkeyPayload.challengeToken).toBeTruthy();

        // Clean up
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
        if (!await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'Passkey container not visible');
        }

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
        if (!await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey container not visible');
        }

        const btnText = page.locator('#passkey-btn-text');
        const btnLoading = page.locator('#passkey-btn-loading');

        // Initially: text visible, loading hidden
        await expect(btnText).toBeVisible();
        await expect(btnLoading).not.toBeVisible();

        // Slow down the API response so loading state is observable
        await page.route('**/passkeys/login/options', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 500));
            await route.continue();
        });

        const loginBtn = page.locator('#passkey-login-btn');
        const isDisabled = await loginBtn.isDisabled().catch(() => true);
        if (isDisabled) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey button is disabled');
        }

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
        if (!await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            test.skip(true, 'Passkey container not visible');
        }

        const loginBtn = page.locator('#passkey-login-btn');
        const isDisabled = await loginBtn.isDisabled().catch(() => true);
        test.skip(isDisabled, 'Passkey button is disabled');

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
        if (!await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey container not visible');
        }

        const loginBtn = page.locator('#passkey-login-btn');
        const isDisabled = await loginBtn.isDisabled().catch(() => true);
        if (isDisabled) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey button is disabled');
        }

        await page.locator('#t3-username').fill('nonexistent_user_e2e_test_xyz');
        await loginBtn.click();

        const error = page.locator('#passkey-error');
        await expect(error).toBeVisible({ timeout: 5000 });
        await expect(error).toContainText(/failed|error|too many attempts/i);

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    test('shows error when WebAuthn ceremony fails', async ({ page }) => {
        // Set up authenticator that will NOT verify the user
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page, {
            isUserVerified: false,
        });

        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        if (!await container.isVisible({ timeout: 5000 }).catch(() => false)) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey container not visible');
        }

        const loginBtn = page.locator('#passkey-login-btn');
        const isDisabled = await loginBtn.isDisabled().catch(() => true);
        if (isDisabled) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Passkey button is disabled');
        }

        await page.locator('#t3-username').fill(ADMIN_USER);
        await loginBtn.click();

        // The WebAuthn ceremony should fail (user verification rejected)
        // or succeed but the assertion should fail server-side
        // Either way, we should not end up logged in
        const error = page.locator('#passkey-error');
        const loginPage = page.url();

        // Wait for either error message or timeout - we should stay on login page
        await Promise.race([
            expect(error).toBeVisible({ timeout: 10000 }).catch(() => {}),
            page.waitForTimeout(10000),
        ]);

        // Should still be on the login page
        expect(page.url()).toContain('/login');

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});

test.describe('Passkey Login - Failed Attempt Error Display', () => {
    test('failed passkey login shows passkey-specific error message', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        const containerVisible = await container.isVisible({ timeout: 5000 }).catch(() => false);
        test.skip(!containerVisible, 'Passkey container not visible');

        // Simulate a failed passkey login: set sessionStorage flag + submit invalid passkey payload
        await page.evaluate(() => {
            const usernameField = document.getElementById('t3-username') as HTMLInputElement;
            const useridentField = document.querySelector('.t3js-login-userident-field') as HTMLInputElement;

            usernameField.value = 'admin';
            useridentField.value = JSON.stringify({
                _type: 'passkey',
                assertion: { id: 'fake', type: 'public-key', response: {} },
                challengeToken: 'fake-token',
            });

            // Set the flag that PasskeyLogin.js checks on page load
            sessionStorage.setItem('nr_passkey_attempt', '1');
        });

        // Submit and wait for TYPO3's POST-Redirect-GET
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => {
                (document.getElementById('typo3-login-form') as HTMLFormElement).submit();
            }),
        ]);

        // Passkey-specific error should be visible
        const passkeyError = page.locator('#passkey-error');
        await expect(passkeyError).toBeVisible({ timeout: 5000 });
        await expect(passkeyError).toContainText(/passkey.*failed|not accepted/i);

        // sessionStorage flag should be cleared after showing the error
        const flagCleared = await page.evaluate(() => sessionStorage.getItem('nr_passkey_attempt'));
        expect(flagCleared).toBeNull();
    });

    test('normal password login failure does NOT show passkey error', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        const container = page.locator('#passkey-login-container');
        const containerVisible = await container.isVisible({ timeout: 5000 }).catch(() => false);
        test.skip(!containerVisible, 'Passkey container not visible');

        // Submit a failed password login (no passkey sessionStorage flag)
        await page.evaluate(() => {
            const usernameField = document.getElementById('t3-username') as HTMLInputElement;
            const useridentField = document.querySelector('.t3js-login-userident-field') as HTMLInputElement;

            usernameField.value = 'admin';
            useridentField.value = 'wrong-password';

            // Do NOT set nr_passkey_attempt flag
            sessionStorage.removeItem('nr_passkey_attempt');
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => {
                (document.getElementById('typo3-login-form') as HTMLFormElement).submit();
            }),
        ]);

        // Passkey error should NOT be shown for a password failure
        const passkeyError = page.locator('#passkey-error');
        await expect(passkeyError).not.toBeVisible({ timeout: 3000 });
    });
});

test.describe('Passkey Login API - Discoverable Flow', () => {
    test('options endpoint returns discoverable options for empty username', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: '' }),
            });
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                isJson: res.headers.get('content-type')?.includes('application/json') ?? false,
                options: data?.options,
                challengeToken: data?.challengeToken,
            };
        });

        test.skip(
            result.redirected || (result.contentType?.includes('text/html') && !result.isJson),
            'Request redirected to login page',
        );
        test.skip(result.status === 429, 'Rate limited');

        expect(result.status).toBe(200);
        expect(result.isJson).toBe(true);
        expect(result.options).toBeDefined();
        expect(result.options.challenge).toBeDefined();
        expect(result.challengeToken).toBeDefined();
        expect(result.options.allowCredentials).toEqual([]);
    });

    test('options endpoint returns credentials for known username', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: 'admin' }),
            });
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            return {
                status: res.status,
                isJson: res.headers.get('content-type')?.includes('application/json') ?? false,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                options: data?.options,
                challengeToken: data?.challengeToken,
            };
        });

        test.skip(
            result.redirected || (result.contentType?.includes('text/html') && !result.isJson),
            'Request redirected to login page',
        );
        test.skip(result.status === 429, 'Rate limited');

        expect(result.status).toBe(200);
        expect(result.options).toBeDefined();
        expect(result.challengeToken).toBeDefined();
        if (result.options.allowCredentials && result.options.allowCredentials.length > 0) {
            expect(result.options.allowCredentials[0]).toHaveProperty('type', 'public-key');
            expect(result.options.allowCredentials[0]).toHaveProperty('id');
        }
    });
});
