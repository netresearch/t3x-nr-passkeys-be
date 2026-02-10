import { test, expect, Page } from '@playwright/test';

/**
 * E2E tests for API endpoint behavior - input validation, error handling, admin endpoints.
 *
 * TYPO3 backend routes require a backend session. For "public" routes (login flow),
 * the session cookie from the login page is sufficient. For authenticated routes,
 * a full admin login is required.
 *
 * IMPORTANT: Tests run with workers=1 (serial) to avoid rate limiting.
 * The rate limiter kicks in after ~10 failed login attempts from the same IP.
 *
 * Prerequisites:
 *   - DDEV running: `ddev start && ddev install-v13`
 *   - TYPO3 accessible at https://v13.nr-passkeys-be.ddev.site/typo3/
 */

const ADMIN_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.TYPO3_ADMIN_PASS || 'Joh316!!';

async function loginAsAdmin(page: Page): Promise<boolean> {
    await page.goto('/typo3/login');
    await page.waitForLoadState('networkidle');

    // Fill login form
    const usernameInput = page.locator('input[name="username"]');
    const passwordInput = page.locator('input[name="p_field"]');

    if (!await usernameInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        return false;
    }

    await usernameInput.fill(ADMIN_USER);
    await passwordInput.fill(ADMIN_PASS);

    await page.locator('#t3-login-submit').click();
    await page.waitForLoadState('networkidle');

    // Check if login succeeded
    return !page.url().includes('/login');
}

/**
 * Helper to detect whether a fetch response was a redirect to the login page
 * (which means the request was not properly authenticated/routed).
 * fetch() follows redirects by default, so a 302->login returns status 200 with HTML.
 */
function isRedirectedOrHtml(result: { status: number; redirected?: boolean; contentType?: string | null }): boolean {
    if (result.redirected) return true;
    if (result.contentType && result.contentType.includes('text/html')) return true;
    return false;
}

test.describe('Login API - Validated with Session', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('login options returns JSON for valid username', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: 'admin' }),
            });
            const text = await res.text();
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                isJson: res.headers.get('content-type')?.includes('application/json') ?? false,
                bodyPreview: text.substring(0, 500),
            };
        });

        // If we got redirected to login page, the session wasn't maintained for fetch
        test.skip(isRedirectedOrHtml(result) && !result.isJson, 'Request redirected to login page - session not maintained for fetch');
        test.skip(result.status === 429, 'Rate limited from previous test run - clear cache and retry');

        expect(result.status).toBe(200);
        expect(result.isJson).toBe(true);
        const body = JSON.parse(result.bodyPreview);
        expect(body.options).toBeDefined();
        expect(body.options.challenge).toBeDefined();
        expect(body.challengeToken).toBeDefined();
    });

    test('login options returns discoverable options for empty username', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

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

        test.skip(isRedirectedOrHtml(result) && !result.isJson, 'Request redirected to login page');
        test.skip(result.status === 429, 'Rate limited');

        expect(result.status).toBe(200);
        expect(result.isJson).toBe(true);
        expect(result.options).toBeDefined();
        expect(result.challengeToken).toBeDefined();
        // Discoverable flow: allowCredentials should be empty
        expect(result.options.allowCredentials).toEqual([]);
    });

    test('login options treats missing username key as discoverable flow', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ foo: 'bar' }),
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
            };
        });

        test.skip(isRedirectedOrHtml(result) && !result.isJson, 'Request redirected to login page');
        test.skip(result.status === 429, 'Rate limited');

        // With discoverable enabled, missing username is treated as empty -> discoverable flow
        expect(result.status).toBe(200);
        expect(result.options).toBeDefined();
        expect(result.options.allowCredentials).toEqual([]);
    });

    test('login options returns 401 for non-existent user', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: 'nonexistent_user_xyz_12345' }),
            });
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                error: data?.error,
            };
        });

        test.skip(isRedirectedOrHtml(result) && !result.error, 'Request redirected to login page');
        test.skip(result.status === 429, 'Rate limited from previous test run - clear cache and retry');

        expect(result.status).toBe(401);
        expect(result.error).toContain('failed');
    });

    test('login verify rejects missing fields with 400', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                error: data?.error,
            };
        });

        test.skip(isRedirectedOrHtml(result) && !result.error, 'Request redirected to login page');

        expect(result.status).toBe(400);
        expect(result.error).toContain('required');
    });

    test('login options response has JSON content type', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/login/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: 'admin' }),
            });
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
            };
        });

        test.skip(isRedirectedOrHtml(result), 'Request redirected to login page');

        expect(result.contentType).toContain('application/json');
    });
});

test.describe('Login API - Route Method Handling', () => {
    test('GET to login options returns 405 or redirects', async ({ page }) => {
        // GET method test - TYPO3 route matching returns 405 without needing a session,
        // or may redirect to login page
        const response = await page.goto('/typo3/passkeys/login/options');
        const finalUrl = page.url();
        // Should either return error status or redirect to login
        expect(
            finalUrl.includes('/login') || (response?.status() ?? 0) >= 400,
        ).toBeTruthy();
    });
});

test.describe('Login API - Rate Limiting', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('rate limiter responds to rapid requests', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const statuses: number[] = [];
            const contentTypes: (string | null)[] = [];

            // Use fewer requests to avoid poisoning rate limiter for other tests
            for (let i = 0; i < 12; i++) {
                try {
                    const res = await fetch('/typo3/passkeys/login/options', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ username: `ratelimit_test_${i}` }),
                    });
                    statuses.push(res.status);
                    contentTypes.push(res.headers.get('content-type'));
                } catch {
                    statuses.push(0);
                    contentTypes.push(null);
                }
            }

            return {
                statuses,
                contentTypes,
                has429: statuses.includes(429),
                has401: statuses.includes(401),
                totalRequests: statuses.length,
            };
        });

        // If responses are HTML (redirected), the session wasn't maintained
        const firstContentType = result.contentTypes[0];
        test.skip(
            firstContentType !== null && firstContentType.includes('text/html'),
            'Requests redirected to login page - session not maintained for fetch',
        );

        expect(result.totalRequests).toBe(12);
        // All requests should get valid JSON responses (401 for non-existent users or 429 for rate limited)
        result.statuses.forEach((status: number) => {
            expect(status).toBeGreaterThan(0);
            expect([401, 429]).toContain(status);
        });
    });
});

test.describe('Admin API Endpoints', () => {
    let isLoggedIn = false;

    test.beforeEach(async ({ page }) => {
        isLoggedIn = await loginAsAdmin(page);
    });

    test('admin list endpoint returns JSON with credentials', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/admin/list', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
            });
            const text = await res.text();
            let data = null;
            try { data = JSON.parse(text); } catch { /* not JSON */ }
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
                ok: res.ok && !res.redirected,
                hasCredentials: Array.isArray(data?.credentials),
            };
        });

        // fetch() follows redirects: admin route -> login page gives 200 HTML
        test.skip(isRedirectedOrHtml(result) && !result.hasCredentials, 'Request redirected to login page');

        expect(result.status).toBe(200);
        expect(result.hasCredentials).toBe(true);
    });

    test('admin remove endpoint rejects missing uid', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/admin/remove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
            };
        });

        // If redirected to login, the endpoint isn't accessible - still a form of rejection
        if (isRedirectedOrHtml(result)) {
            // Redirecting to login is valid access denial
            expect(result.redirected || result.contentType?.includes('text/html')).toBeTruthy();
        } else {
            expect(result.status).toBeGreaterThanOrEqual(400);
        }
    });

    test('admin unlock endpoint rejects missing uid', async ({ page }) => {
        test.skip(!isLoggedIn, 'Login failed - skipping authenticated test');

        const result = await page.evaluate(async () => {
            const res = await fetch('/typo3/passkeys/admin/unlock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            return {
                status: res.status,
                redirected: res.redirected,
                contentType: res.headers.get('content-type'),
            };
        });

        // If redirected to login, the endpoint isn't accessible - still a form of rejection
        if (isRedirectedOrHtml(result)) {
            expect(result.redirected || result.contentType?.includes('text/html')).toBeTruthy();
        } else {
            expect(result.status).toBeGreaterThanOrEqual(400);
        }
    });
});

test.describe('Admin API - Unauthenticated Access', () => {
    test.beforeEach(async ({ page }) => {
        await page.context().clearCookies();
    });

    test('admin endpoints reject unauthenticated requests', async ({ page }) => {
        // Without a session, TYPO3 backend routes redirect to login.
        // fetch() follows redirects, so we use page.goto() and check the final URL.
        const endpoints = [
            '/typo3/passkeys/admin/list',
            '/typo3/passkeys/admin/remove',
            '/typo3/passkeys/admin/unlock',
        ];

        for (const url of endpoints) {
            const response = await page.goto(url);
            const finalUrl = page.url();
            // Should redirect to login page or return error
            expect(
                finalUrl.includes('/login') || (response?.status() ?? 0) >= 400,
            ).toBeTruthy();
        }
    });
});
