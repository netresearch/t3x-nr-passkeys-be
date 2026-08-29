/**
 * Tests for the conditional-UI (autofill) ceremony and the failed-attempt
 * marker in the SHIPPED login module.
 *
 * These import Resources/Public/JavaScript/PasskeyLogin.js itself and drive it
 * through a jsdom login form with a stubbed WebAuthn API, so the assertions
 * cover the real control flow. The module runs init() on import, which is why
 * each test resets the module registry and rebuilds the DOM first.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const OPTIONS_URL = 'https://example.test/typo3/passkeys/login/options';
const VERIFY_URL = 'https://example.test/typo3/passkeys/login/verify';

/** Minimal login form matching the ids PasskeyLogin.js looks for. */
function buildLoginDom() {
    document.body.innerHTML = `
        <form id="typo3-login-form">
            <input id="t3-username" name="username" />
            <input class="t3js-login-userident-field" name="userident" />
            <div id="t3-login-submit-section"></div>
        </form>
    `;
    // jsdom has no form submission; record the call instead.
    const form = document.getElementById('typo3-login-form');
    form.submit = vi.fn();
    return form;
}

/** base64url payload the module can decode without caring about the content. */
const CHALLENGE_B64 = 'YWJjZGVmZ2hpamtsbW5vcA';

function assertionOptionsResponse() {
    return {
        options: {
            challenge: CHALLENGE_B64,
            rpId: 'example.test',
            userVerification: 'required',
        },
        challengeToken: 'challenge-token-1',
    };
}

/** A credential shaped like the one navigator.credentials.get() resolves with. */
function fakeAssertion() {
    const buf = new Uint8Array([1, 2, 3, 4]).buffer;
    return {
        rawId: buf,
        type: 'public-key',
        response: {
            clientDataJSON: buf,
            authenticatorData: buf,
            signature: buf,
            userHandle: null,
        },
    };
}

function installWebAuthnStub(getImpl) {
    window.PublicKeyCredential = function () {};
    window.PublicKeyCredential.isConditionalMediationAvailable = vi.fn(async () => true);
    const get = vi.fn(getImpl);
    Object.defineProperty(navigator, 'credentials', {
        value: { get },
        configurable: true,
        writable: true,
    });
    return get;
}

/** Let queued promise callbacks run; the module chains several awaits. */
async function settle(rounds = 12) {
    for (let i = 0; i < rounds; i++) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    vi.resetModules();
    vi.useRealTimers();
    sessionStorage.clear();
    buildLoginDom();
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    window.NrPasskeysBeConfig = {
        loginOptionsUrl: OPTIONS_URL,
        loginVerifyUrl: VERIFY_URL,
        rpId: 'example.test',
        origin: 'https://example.test',
        discoverableEnabled: true,
        challengeTtlSeconds: 120,
        labels: {},
    };
});

afterEach(() => {
    vi.unstubAllGlobals();
    delete window.NrPasskeysBeConfig;
});

describe('failed-attempt marker', () => {
    // The marker is written before the form is submitted, so a SUCCESSFUL login
    // leaves it behind: the user lands in the backend, works, and logs out. The
    // login page then loads with the marker still set. Reporting a failure there
    // is wrong — the login it refers to had worked.
    it('stays silent when the marker is older than the failure window', async () => {
        installWebAuthnStub(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
        sessionStorage.setItem('nr_passkey_attempt', String(Date.now() - 10 * 60 * 1000));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await settle();

        const error = document.getElementById('passkey-error');
        expect(error.textContent.trim()).toBe('');
        expect(sessionStorage.getItem('nr_passkey_attempt')).toBeNull();
    });

    it('reports the failure when the marker is seconds old', async () => {
        installWebAuthnStub(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
        sessionStorage.setItem('nr_passkey_attempt', String(Date.now() - 2000));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await settle();

        const error = document.getElementById('passkey-error');
        expect(error.textContent).toContain('not accepted');
        expect(sessionStorage.getItem('nr_passkey_attempt')).toBeNull();
    });

    it('ignores a legacy marker that carries no timestamp', async () => {
        installWebAuthnStub(async () => fakeAssertion());
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
        sessionStorage.setItem('nr_passkey_attempt', '1');

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await settle();

        expect(document.getElementById('passkey-error').textContent.trim()).toBe('');
    });
});

describe('conditional UI ceremony', () => {
    it('re-arms after the server rejects the picked passkey', async () => {
        // Without a re-arm the ceremony is spent: the autofill menu then offers
        // password-manager entries only, and no passkey can be picked again
        // until the page is reloaded.
        const get = installWebAuthnStub(async () => fakeAssertion());
        const fetchMock = vi.fn(async (url) => {
            if (String(url) === OPTIONS_URL) {
                return { ok: true, status: 200, json: async () => assertionOptionsResponse() };
            }
            return { ok: false, status: 401, json: async () => ({ error: 'Verification failed' }) };
        });
        vi.stubGlobal('fetch', fetchMock);

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await settle(40);

        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(get.mock.calls[0][0].mediation).toBe('conditional');
        expect(get.mock.calls[1][0].mediation).toBe('conditional');
        // The second ceremony must not reuse the spent challenge.
        const optionsCalls = fetchMock.mock.calls.filter((c) => String(c[0]) === OPTIONS_URL);
        expect(optionsCalls.length).toBeGreaterThanOrEqual(2);
    });

    it('gives up re-arming when every ceremony fails at once', async () => {
        // A browser that rejects conditional mediation the moment it is asked —
        // no authenticator attached, WebAuthn blocked by policy, the autofill
        // dismissed — used to have the page ask for fresh options as fast as the
        // server could answer: 39 297 requests to /passkeys/login/options in one
        // four-minute e2e run (#129). The retries are bounded now.
        vi.useFakeTimers();
        let attempts = 0;
        const get = installWebAuthnStub(async () => {
            attempts += 1;
            // Escape hatch so an unbounded implementation fails this test
            // instead of hanging it: AbortError is the one outcome that does
            // not re-arm.
            const error = new Error('rejected');
            error.name = attempts > 40 ? 'AbortError' : 'NotAllowedError';
            throw error;
        });
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        }));
        vi.stubGlobal('fetch', fetchMock);

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        // Past every backoff step (0, 500, 2000, 8000 ms) and short of the 60 s
        // challenge refresh, which is a separate, time-based re-arm.
        await vi.advanceTimersByTimeAsync(30000);

        const optionsCalls = () => fetchMock.mock.calls.filter((c) => String(c[0]) === OPTIONS_URL);
        expect(optionsCalls().length).toBeLessThanOrEqual(5);
        expect(get.mock.calls.length).toBeLessThanOrEqual(5);

        // What is left afterwards is the refresh timer alone: one attempt per
        // minute, not one per round trip.
        await vi.advanceTimersByTimeAsync(300000);
        expect(optionsCalls().length).toBeLessThanOrEqual(11);
        vi.useRealTimers();
    });

    it('abandons a ceremony whose availability check outlived its turn', async () => {
        // The availability check is awaited before the ceremony records which
        // generation it belongs to. A retry callback has already cleared its own
        // timer by then, so nothing can cancel it — the explicit button can take
        // over during that await, and the continuation must not arm a
        // conditional ceremony inside it.
        let releaseAvailability;
        const availability = new Promise((resolve) => {
            releaseAvailability = resolve;
        });
        const get = installWebAuthnStub(() => new Promise(() => {}));
        window.PublicKeyCredential.isConditionalMediationAvailable = vi.fn(() => availability);
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        })));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await settle();
        expect(get).not.toHaveBeenCalled();

        // The button takes over while the availability check is still pending.
        document.getElementById('t3-username').value = 'admin';
        document.getElementById('passkey-login-btn').click();
        await settle();

        releaseAvailability(true);
        await settle(20);

        const conditionalCalls = get.mock.calls.filter((c) => c[0].mediation === 'conditional');
        expect(conditionalCalls).toHaveLength(0);
    });

    it('drops a pending retry when the explicit button takes over', async () => {
        // Only one credentials.get() may be in flight. A retry that was already
        // scheduled must not wake up inside the ceremony the button started.
        vi.useFakeTimers();
        const get = installWebAuthnStub(async (options) => {
            if (options.mediation === 'conditional') {
                const error = new Error('dismissed');
                error.name = 'NotAllowedError';
                throw error;
            }
            // The explicit ceremony: never settles, so it stays "in flight"
            // exactly as it would while the user is looking at the prompt.
            return new Promise(() => {});
        });
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        })));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        // The immediate retry has run and failed; the next one is queued behind
        // a delay.
        await vi.advanceTimersByTimeAsync(0);
        const conditionalCalls = () =>
            get.mock.calls.filter((c) => c[0].mediation === 'conditional').length;
        const before = conditionalCalls();
        expect(before).toBeGreaterThan(0);

        document.getElementById('t3-username').value = 'admin';
        document.getElementById('passkey-login-btn').click();
        await vi.advanceTimersByTimeAsync(30000);

        expect(conditionalCalls()).toBe(before);
        vi.useRealTimers();
    });

    it('stops and stays quiet when the browser does not do discoverable credentials', async () => {
        // Headless Chromium without an authenticator answers exactly this, and
        // so does any browser that cannot serve resident credentials. It is a
        // statement about the browser, not a fault: no console noise, and no
        // point asking again.
        vi.useFakeTimers();
        const errors = [];
        const consoleError = vi.spyOn(console, 'error').mockImplementation((...args) => {
            errors.push(args.join(' '));
        });
        const get = installWebAuthnStub(async () => {
            const error = new Error("Resident credentials or empty 'allowCredentials' lists are not supported at this time.");
            error.name = 'NotSupportedError';
            throw error;
        });
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        })));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        // Well past the 60 s challenge refresh, which is armed before the
        // ceremony runs and would otherwise ask again once a minute for as long
        // as the page stays open.
        await vi.advanceTimersByTimeAsync(300000);

        expect(get).toHaveBeenCalledTimes(1);
        expect(errors).toEqual([]);
        consoleError.mockRestore();
        vi.useRealTimers();
    });

    it('lets typing in the username field start a fresh run of attempts', async () => {
        vi.useFakeTimers();
        const get = installWebAuthnStub(async () => {
            const error = new Error('rejected');
            error.name = 'NotAllowedError';
            throw error;
        });
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        })));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await vi.advanceTimersByTimeAsync(120000);
        const exhausted = get.mock.calls.length;

        document.getElementById('t3-username').dispatchEvent(new Event('input'));
        await vi.advanceTimersByTimeAsync(120000);

        expect(get.mock.calls.length).toBeGreaterThan(exhausted);
        vi.useRealTimers();
    });

    it('refreshes the challenge before it expires while the field is unfocused', async () => {
        vi.useFakeTimers();
        // Never settles: models the ceremony waiting for the user to pick.
        const get = installWebAuthnStub(() => new Promise(() => {}));
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        }));
        vi.stubGlobal('fetch', fetchMock);

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await vi.advanceTimersByTimeAsync(0);
        expect(get).toHaveBeenCalledTimes(1);

        // Half of the 120 s TTL: the armed challenge is swapped for a fresh one
        // well before the server would reject it as expired.
        await vi.advanceTimersByTimeAsync(60000);
        await vi.advanceTimersByTimeAsync(0);

        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(get.mock.calls[0][0].signal.aborted).toBe(true);
        vi.useRealTimers();
    });

    it('does not close the autofill menu while the username field has focus', async () => {
        vi.useFakeTimers();
        const get = installWebAuthnStub(() => new Promise(() => {}));
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => assertionOptionsResponse(),
        })));

        await import('../../Resources/Public/JavaScript/PasskeyLogin.js');
        await vi.advanceTimersByTimeAsync(0);

        const username = document.getElementById('t3-username');
        username.focus();
        await vi.advanceTimersByTimeAsync(60000);
        await vi.advanceTimersByTimeAsync(0);

        // Aborting here would tear down the menu the user is looking at.
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0].signal.aborted).toBe(false);

        // Once focus leaves, the stale challenge is replaced.
        username.blur();
        await vi.advanceTimersByTimeAsync(0);
        expect(get.mock.calls.length).toBeGreaterThanOrEqual(2);
        vi.useRealTimers();
    });
});
