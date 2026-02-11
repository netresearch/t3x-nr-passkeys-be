/**
 * DOM integration tests for PasskeyManagement.js inline label input.
 *
 * Tests the IIFE behavior with a jsdom environment: label input reading,
 * Enter key handler, input clearing on success, and disabling during loading.
 */
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const SCRIPT_PATH = resolve(__dirname, '../../Resources/Public/JavaScript/PasskeyManagement.js');
const scriptSource = readFileSync(SCRIPT_PATH, 'utf-8');

/**
 * Build the minimal DOM structure that PasskeyManagement.js expects.
 */
function buildDom() {
    document.body.innerHTML = `
        <div id="passkey-management-container"
             data-list-url="/typo3/passkeys/manage/list"
             data-register-options-url="/typo3/passkeys/manage/registration/options"
             data-register-verify-url="/typo3/passkeys/manage/registration/verify"
             data-rename-url="/typo3/passkeys/manage/rename"
             data-remove-url="/typo3/passkeys/manage/remove">
            <div id="passkey-message" class="alert d-none" role="alert"></div>
            <div id="passkey-single-warning" class="alert alert-warning d-none"></div>
            <div class="mb-3">
                <div class="input-group">
                    <input type="text" id="passkey-label-input" class="form-control"
                           maxlength="128" placeholder='e.g. "Work Laptop", "Phone"' />
                    <button type="button" id="passkey-add-btn" class="btn btn-primary">Add Passkey</button>
                </div>
                <span class="mt-1 d-inline-block text-muted">
                    Registered: <span id="passkey-count">0</span>
                </span>
            </div>
            <div id="passkey-empty" class="alert alert-info d-none"></div>
            <table class="table" id="passkey-list-table">
                <thead><tr><th>Name</th><th>Created</th><th>Last Used</th><th>Actions</th></tr></thead>
                <tbody id="passkey-list-body"></tbody>
            </table>
        </div>
    `;
}

/**
 * Mock PublicKeyCredential and navigator.credentials for WebAuthn API.
 */
function mockWebAuthn() {
    window.PublicKeyCredential = /** @type {any} */ (function () {});
    Object.defineProperty(navigator, 'credentials', {
        value: {
            create: vi.fn().mockResolvedValue({
                rawId: new Uint8Array([1, 2, 3]).buffer,
                type: 'public-key',
                response: {
                    clientDataJSON: new Uint8Array([4, 5, 6]).buffer,
                    attestationObject: new Uint8Array([7, 8, 9]).buffer,
                    getTransports: () => ['internal'],
                },
            }),
            get: vi.fn(),
        },
        writable: true,
        configurable: true,
    });
}

/**
 * Create a mock fetch that returns successful responses for the passkey endpoints.
 */
function createMockFetch() {
    return vi.fn().mockImplementation((url) => {
        if (typeof url === 'string' && url.includes('/list')) {
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ credentials: [], enforcementEnabled: false }),
            });
        }
        if (typeof url === 'string' && url.includes('/registration/options')) {
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    options: {
                        challenge: 'dGVzdC1jaGFsbGVuZ2U',
                        rp: { name: 'Test', id: 'localhost' },
                        user: { id: 'dGVzdA', name: 'admin', displayName: 'Admin' },
                        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
                        timeout: 60000,
                        attestation: 'none',
                        authenticatorSelection: {},
                    },
                    challengeToken: 'test-token',
                }),
            });
        }
        if (typeof url === 'string' && url.includes('/registration/verify')) {
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true }),
            });
        }
        return Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({}),
        });
    });
}

/**
 * Run the PasskeyManagement IIFE in the current jsdom context.
 */
function loadScript() {
    // The script uses document.readyState check — in jsdom it's 'complete',
    // so it calls init() synchronously
    // eslint-disable-next-line no-eval
    eval(scriptSource);
}

describe('PasskeyManagement inline label input', () => {
    let mockFetch;

    beforeEach(() => {
        buildDom();
        mockWebAuthn();
        mockFetch = createMockFetch();
        vi.stubGlobal('fetch', mockFetch);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('should find #passkey-label-input element', () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        expect(input).not.toBeNull();
        expect(input.tagName).toBe('INPUT');
        expect(input.type).toBe('text');
    });

    it('should have maxlength=128 on the label input', () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        expect(input.maxLength).toBe(128);
    });

    it('should have placeholder text on the label input', () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        expect(input.placeholder).toContain('Work Laptop');
        expect(input.placeholder).toContain('Phone');
    });

    it('should read label from input value on Add Passkey click', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = 'My Test Key';
        btn.click();

        // Wait for async operations to complete
        await vi.waitFor(() => {
            const verifyCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
            );
            expect(verifyCall).toBeTruthy();
        });

        const verifyCall = mockFetch.mock.calls.find(
            (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
        );
        const body = JSON.parse(verifyCall[1].body);
        expect(body.label).toBe('My Test Key');
    });

    it('should use default label "Passkey" when input is empty', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = '';
        btn.click();

        await vi.waitFor(() => {
            const verifyCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
            );
            expect(verifyCall).toBeTruthy();
        });

        const verifyCall = mockFetch.mock.calls.find(
            (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
        );
        const body = JSON.parse(verifyCall[1].body);
        expect(body.label).toBe('Passkey');
    });

    it('should use default label "Passkey" when input is whitespace only', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = '   ';
        btn.click();

        await vi.waitFor(() => {
            const verifyCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
            );
            expect(verifyCall).toBeTruthy();
        });

        const verifyCall = mockFetch.mock.calls.find(
            (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
        );
        const body = JSON.parse(verifyCall[1].body);
        expect(body.label).toBe('Passkey');
    });

    it('should trim whitespace from label', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = '  Work Laptop  ';
        btn.click();

        await vi.waitFor(() => {
            const verifyCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
            );
            expect(verifyCall).toBeTruthy();
        });

        const verifyCall = mockFetch.mock.calls.find(
            (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
        );
        const body = JSON.parse(verifyCall[1].body);
        expect(body.label).toBe('Work Laptop');
    });

    it('should clear input after successful registration', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = 'My Passkey';
        btn.click();

        await vi.waitFor(() => {
            expect(input.value).toBe('');
        });
    });

    it('should disable input during registration', async () => {
        // Use a deferred fetch to control timing
        let resolveOptions;
        const deferredFetch = vi.fn().mockImplementation((url) => {
            if (typeof url === 'string' && url.includes('/list')) {
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ credentials: [], enforcementEnabled: false }),
                });
            }
            if (typeof url === 'string' && url.includes('/registration/options')) {
                return new Promise((resolve) => {
                    resolveOptions = resolve;
                });
            }
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({}),
            });
        });
        vi.stubGlobal('fetch', deferredFetch);

        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = 'Test Key';
        btn.click();

        // Input and button should be disabled while loading
        await vi.waitFor(() => {
            expect(input.disabled).toBe(true);
            expect(btn.disabled).toBe(true);
        });

        // Resolve to let it finish (prevent unhandled rejection)
        resolveOptions({
            ok: false,
            status: 500,
            json: () => Promise.resolve({ error: 'test' }),
        });
    });

    it('should trigger registration on Enter key in label input', async () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');

        input.value = 'Enter Key Test';
        const event = new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
        });
        input.dispatchEvent(event);

        await vi.waitFor(() => {
            const optionsCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/options'),
            );
            expect(optionsCall).toBeTruthy();
        });

        await vi.waitFor(() => {
            const verifyCall = mockFetch.mock.calls.find(
                (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
            );
            expect(verifyCall).toBeTruthy();
        });

        const verifyCall = mockFetch.mock.calls.find(
            (call) => typeof call[0] === 'string' && call[0].includes('/registration/verify'),
        );
        const body = JSON.parse(verifyCall[1].body);
        expect(body.label).toBe('Enter Key Test');
    });

    it('should NOT call prompt() for label input', () => {
        const promptSpy = vi.spyOn(window, 'prompt');
        loadScript();

        const btn = document.getElementById('passkey-add-btn');
        const input = document.getElementById('passkey-label-input');
        input.value = 'No Prompt Test';
        btn.click();

        expect(promptSpy).not.toHaveBeenCalled();
    });

    it('should place label input and button inside input-group', () => {
        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        expect(input.parentElement).toBe(btn.parentElement);
        expect(input.parentElement.classList.contains('input-group')).toBe(true);
    });

    it('should re-enable input after failed registration', async () => {
        // Mock fetch to fail on registration options
        const failFetch = vi.fn().mockImplementation((url) => {
            if (typeof url === 'string' && url.includes('/list')) {
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ credentials: [], enforcementEnabled: false }),
                });
            }
            if (typeof url === 'string' && url.includes('/registration/options')) {
                return Promise.resolve({
                    ok: false,
                    status: 500,
                    json: () => Promise.resolve({ error: 'Server error' }),
                });
            }
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({}),
            });
        });
        vi.stubGlobal('fetch', failFetch);

        loadScript();
        const input = document.getElementById('passkey-label-input');
        const btn = document.getElementById('passkey-add-btn');

        input.value = 'Fail Test';
        btn.click();

        // Should re-enable after failure
        await vi.waitFor(() => {
            expect(input.disabled).toBe(false);
            expect(btn.disabled).toBe(false);
        });
    });
});
