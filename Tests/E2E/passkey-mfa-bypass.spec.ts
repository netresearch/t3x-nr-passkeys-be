import { test, expect, Page, CDPSession } from '@playwright/test';

/**
 * E2E coverage for the skipMfaOnPasskeyAuth feature.
 *
 * The extension writes the standard TYPO3 session key `mfa = true` after a
 * successful passkey authentication so that TYPO3's
 * AbstractUserAuthentication::evaluateMfaRequirements() short-circuits the
 * MFA challenge. These tests verify that a passkey-authenticated user is
 * never redirected through /auth/mfa during the login flow.
 *
 * Scope note: configuring an active TOTP provider on the test user would
 * exercise the bypass more directly, but requires seeding be_users.mfa via
 * DB fixture or admin API — infrastructure that does not exist in this
 * suite yet. The assertions below catch the regression scenarios that do
 * not depend on a pre-existing MFA provider: (a) the login never lands on
 * /auth/mfa and (b) post-login the user reaches a normal backend module.
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
    if (!await usernameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        return false;
    }
    await usernameInput.fill(ADMIN_USER);
    await page.locator('input[name="p_field"]').fill(ADMIN_PASS);
    await page.locator('#t3-login-submit').click();
    await page.waitForLoadState('networkidle');
    return !page.url().includes('/login');
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

async function registerPasskey(page: Page): Promise<boolean> {
    const optionsUrl = await getAjaxUrl(page, 'passkeys_manage_registration_options');
    if (!optionsUrl) return false;

    const optResponse = await page.request.post(optionsUrl, {
        headers: { 'Content-Type': 'application/json' },
        data: {},
    });
    if (!optResponse.ok()) return false;
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
    if (!credentialData) return false;

    const verifyUrl = await getAjaxUrl(page, 'passkeys_manage_registration_verify');
    if (!verifyUrl) return false;

    const verifyResponse = await page.request.post(verifyUrl, {
        headers: { 'Content-Type': 'application/json' },
        data: {
            credential: credentialData,
            challengeToken: optData.challengeToken,
            label: 'E2E MFA Bypass Test Key',
        },
    });
    return verifyResponse.ok();
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
    // TODO: shares the rpId root cause with passkey-login-flow.spec.ts — the
    // CDP virtual authenticator in the CI Chromium throws
    //   SecurityError: The relying party ID is not a registrable domain suffix
    //   of, nor equal to the current domain
    // on navigator.credentials.create, because the WebAuthn rpId configured
    // for the extension does not match the CI PHP server's localhost:8080.
    // Unit + functional tests already cover the session-key contract for
    // skipMfaOnPasskeyAuth; this E2E spec is supplementary and will be
    // re-enabled once the shared rpId configuration is fixed in the E2E
    // environment (same follow-up that re-enables the three .fixme() tests
    // in passkey-login-flow.spec.ts).
    test.fixme('passkey login never redirects through /auth/mfa', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Password login failed');

        const { cdp, authenticatorId } = await setupVirtualAuthenticator(page);

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');

        const registered = await registerPasskey(page);
        if (!registered) {
            await removeVirtualAuthenticator(cdp, authenticatorId);
            test.skip(true, 'Could not register passkey for test');
            return;
        }

        // Track every URL the browser visits from this point on. If TYPO3 ever
        // routes the passkey-authenticated user through the MFA challenge, we
        // want the assertion to fail with a concrete breadcrumb.
        const visitedUrls: string[] = [];
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                visitedUrls.push(frame.url());
            }
        });

        await page.context().clearCookies();
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#passkey-login-container')).toBeVisible({ timeout: 5000 });
        await page.locator('#t3-username').fill(ADMIN_USER);
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
});
