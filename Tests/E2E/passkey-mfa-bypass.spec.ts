import { createHmac } from 'node:crypto';
import { Page, CDPSession } from '@playwright/test';
import { test, expect, loginAsAdmin, ADMIN_PASS, ADMIN_USER } from './fixtures';

/**
 * E2E coverage for the skipMfaOnPasskeyAuth feature.
 *
 * The extension writes the standard TYPO3 session key `mfa = true` after a
 * successful passkey authentication so that TYPO3's
 * AbstractUserAuthentication::evaluateMfaRequirements() short-circuits the
 * MFA challenge. These tests verify that a passkey-authenticated user is
 * never redirected through /auth/mfa during the login flow.
 *
 * The first test uses the admin, who has no MFA provider: the login never
 * lands on /auth/mfa and reaches a normal backend module. The second uses
 * e2e_passkey_totp (Tests/Fixtures/e2e-admin.sql), who has an active TOTP
 * provider: a passkey login on a fresh browser session must skip the TOTP
 * challenge, a password login must still ask for it.
 *
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

const TOTP_USER = 'e2e_passkey_totp';
// Synthetic secret seeded in Tests/Fixtures/e2e-admin.sql.
const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

/** RFC 6238 code (SHA-1, 30 s, 6 digits), the parameters TYPO3's TOTP provider uses. */
function totp(secret: string, now = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(now / 30000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;
    return code.toString().padStart(6, '0');
}

async function passwordLogin(page: Page, username: string): Promise<void> {
    await page.goto('/typo3/login');
    await page.locator('input[name="username"]').fill(username);
    await page.locator('input[name="p_field"]').fill(ADMIN_PASS);
    await page.locator('#t3-login-submit').click();
    await page.waitForLoadState('networkidle');
}

async function setupVirtualAuthenticator(
    page: Page,
): Promise<{ cdp: CDPSession; authenticatorId: string }> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
        },
    });
    return { cdp, authenticatorId };
}

/**
 * Turn the authenticator's automatic user-presence simulation on or off.
 *
 * With it on, the virtual authenticator approves every ceremony the moment it
 * is asked — including the conditional (autofill) one PasskeyLogin.js arms on
 * the login page. Once a resident credential exists, opening /typo3/login then
 * logs the browser straight back into the backend before any assertion runs.
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

async function getAjaxUrl(page: Page, routeKey: string): Promise<string | null> {
    return page.evaluate((key: string) => {
        return (window as any).TYPO3?.settings?.ajaxUrls?.[key] ?? null;
    }, routeKey);
}

async function registerPasskey(page: Page): Promise<{ success: boolean; error?: string }> {
    const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
    if (!optionsUrl) {
        return { success: false, error: 'AJAX URL passkeys_manage_registration_options not found in TYPO3.settings' };
    }

    let optResponse = await page.request.post(optionsUrl, {
        headers: { 'Content-Type': 'application/json' },
        data: {},
    });

    // Enrolling a credential is a Sudo Mode route (see
    // Configuration/Backend/AjaxRoutes.php): TYPO3 answers the first call with
    // 422 and the URI that takes the step-up. A real user types their password
    // into the modal at this point, so the test does the same and repeats the
    // request.
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

    const credentialData = await page.evaluate(async (opts) => {
        const toBuf = (b64url: string): ArrayBuffer => {
            const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
            const pad = (4 - (b64.length % 4)) % 4;
            const bin = atob(b64 + '='.repeat(pad));
            const buf = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
            return buf.buffer;
        };
        const fromBuf = (buf: ArrayBuffer): string => {
            const bytes = new Uint8Array(buf);
            let bin = '';
            for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        };

        const cred = await navigator.credentials.create({
            publicKey: {
                challenge: toBuf(opts.challenge),
                rp: { name: opts.rp.name, id: opts.rp.id },
                user: {
                    id: toBuf(opts.user.id),
                    name: opts.user.name,
                    displayName: opts.user.displayName,
                },
                pubKeyCredParams: (opts.pubKeyCredParams || []).map((p: any) => ({ type: p.type, alg: p.alg })),
                timeout: opts.timeout || 60000,
                attestation: opts.attestation || 'none',
                authenticatorSelection: opts.authenticatorSelection || {},
                excludeCredentials: (opts.excludeCredentials || []).map((c: any) => ({
                    type: c.type,
                    id: toBuf(c.id),
                    transports: c.transports || [],
                })),
            },
        }) as PublicKeyCredential | null;
        if (!cred) return null;
        const att = cred.response as AuthenticatorAttestationResponse;
        return {
            id: fromBuf(cred.rawId),
            rawId: fromBuf(cred.rawId),
            type: cred.type,
            response: {
                clientDataJSON: fromBuf(att.clientDataJSON),
                attestationObject: fromBuf(att.attestationObject),
            },
        };
    }, optData.options);
    if (!credentialData) {
        return { success: false, error: 'navigator.credentials.create() returned null' };
    }

    const verifyUrl = await getAjaxUrl(page, 'passkeys_manage_registration_verify');
    if (!verifyUrl) {
        return { success: false, error: 'AJAX URL passkeys_manage_registration_verify not found in TYPO3.settings' };
    }

    const verifyResponse = await page.request.post(verifyUrl, {
        headers: { 'Content-Type': 'application/json' },
        data: {
            credential: credentialData,
            challengeToken: optData.challengeToken,
            label: 'E2E MFA Bypass Test Key',
        },
    });
    if (!verifyResponse.ok()) {
        return { success: false, error: `Verify ${verifyResponse.status()}: ${(await verifyResponse.text()).substring(0, 200)}` };
    }

    return { success: true };
}

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
            if (cred.label === 'E2E MFA Bypass Test Key') {
                await page.request.post(removeUrl, {
                    headers: { 'Content-Type': 'application/json' },
                    data: { uid: cred.uid },
                });
            }
        }
    } catch { /* ignore cleanup errors */ }
}

test.describe('Passkey login — MFA bypass', () => {
    // Registers a real passkey, logs in with it and asserts that TYPO3 never
    // routes the session through the MFA challenge. The unit and functional
    // suites cover the session-key contract for skipMfaOnPasskeyAuth; this one
    // covers the redirect a user would actually see.
    test('passkey login never redirects through /auth/mfa', async ({ page }) => {
        await loginAsAdmin(page);

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const registered = await registerPasskey(page);
        if (!registered.success) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
        }
        // A registration that does not work is this test's subject failing, not a
        // reason to skip it: without a passkey there is no passkey login to check
        // against the MFA redirect.
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        // Track every URL the browser visits from this point on. If TYPO3 ever
        // routes the passkey-authenticated user through the MFA challenge, we
        // want the assertion to fail with a concrete breadcrumb.
        const visitedUrls: string[] = [];
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                visitedUrls.push(frame.url());
            }
        });

        // A resident credential now exists, so the conditional ceremony would log
        // the browser back in the moment the login page loads.
        await setAutomaticPresence(cdp, authenticatorId, false);

        await page.context().clearCookies();
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#passkey-login-container')).toBeVisible({ timeout: 5000 });
        await page.locator('#t3-username').fill(ADMIN_USER);
        // The click is this test's ceremony, so the authenticator answers again.
        await setAutomaticPresence(cdp, authenticatorId, true);
        await page.locator('#passkey-login-btn').click();

        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });

        // Core assertions: the final URL is a normal backend page, and the MFA
        // challenge page was never visited during the login ceremony.
        expect(page.url()).not.toContain('/auth/mfa');
        const mfaHits = visitedUrls.filter((u) => u.includes('/auth/mfa'));
        expect(mfaHits, `Unexpected /auth/mfa navigation(s): ${JSON.stringify(mfaHits)}`).toHaveLength(0);

        await cleanupTestCredentials(page);
        await removeVirtualAuthenticator(cdp, authenticatorId);
    });

    // The user has an active TOTP provider and starts without a session, as a
    // real login does. TYPO3 creates that session only after the passkey has
    // been verified; the passkey-login-completion middleware must still let the
    // user skip the TOTP challenge.
    test('passkey login on a fresh session skips an active TOTP provider', async ({ page }) => {
        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);
        // No passkey exists yet, so the conditional ceremony has nothing to offer.
        await setAutomaticPresence(cdp, authenticatorId, false);

        await passwordLogin(page, TOTP_USER);
        await expect(page).toHaveURL(/\/typo3\/auth\/mfa/);
        // A code computed just before the 30 s window ends can arrive late.
        const leftInWindow = 30000 - (Date.now() % 30000);
        if (leftInWindow < 3000) {
            await page.waitForTimeout(leftInWindow + 500);
        }
        await page.locator('input[name="totp"]').fill(totp(TOTP_SECRET));
        await page.locator('button[type="submit"]').first().click();
        await expect(page.locator('#typo3-contentIframe')).toBeAttached();

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');
        await setAutomaticPresence(cdp, authenticatorId, true);
        const registered = await registerPasskey(page);
        expect(registered.success, `Registration failed: ${registered.error}`).toBe(true);

        const visitedUrls: string[] = [];
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                visitedUrls.push(frame.url());
            }
        });

        await setAutomaticPresence(cdp, authenticatorId, false);
        await page.context().clearCookies();
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#passkey-login-container')).toBeVisible({ timeout: 5000 });
        await page.locator('#t3-username').fill(TOTP_USER);
        await setAutomaticPresence(cdp, authenticatorId, true);
        await page.locator('#passkey-login-btn').click();

        await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
        await expect(page.locator('#typo3-contentIframe')).toBeAttached();
        const mfaHits = visitedUrls.filter((u) => u.includes('/mfa'));
        expect(mfaHits, `Unexpected MFA navigation(s): ${JSON.stringify(mfaHits)}`).toHaveLength(0);

        await cleanupTestCredentials(page);

        // A password login still goes through the TOTP challenge.
        await setAutomaticPresence(cdp, authenticatorId, false);
        await page.context().clearCookies();
        await passwordLogin(page, TOTP_USER);
        await expect(page).toHaveURL(/\/typo3\/auth\/mfa/);

        await removeVirtualAuthenticator(cdp, authenticatorId);
    });
});
