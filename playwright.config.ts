import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E test configuration for nr_passkeys_be TYPO3 extension.
 *
 * Tests run against a TYPO3 instance, and do not start one:
 *   - `./Build/Scripts/runTests.sh -s e2e` installs one in containers and
 *     passes its address in TYPO3_BASE_URL
 *   - set TYPO3_BASE_URL yourself to use an instance that already runs
 * No CI workflow runs this suite at present.
 */

const target = process.env.TYPO3_BASE_URL || 'http://localhost:8080';
const targetUrl = new URL(target);

/**
 * WebAuthn exists only in a secure context. The runner serves TYPO3 from a
 * container the browser reaches by name over plain http, which Chromium does
 * not trust: window.isSecureContext is false, navigator.credentials is
 * undefined, and every ceremony spec then fails on the environment instead of
 * on the code.
 *
 * Chromium does trust anything under .localhost, so the browser is pointed at
 * http://typo3.localhost and --host-resolver-rules sends that name to the
 * container. Measured in this image: isSecureContext true, PublicKeyCredential
 * defined, CDP virtual authenticator attaches.
 *
 * --unsafely-treat-insecure-origin-as-secure is the obvious alternative and
 * does not work here: Chromium only honours it together with --user-data-dir,
 * which browserType.launch() rejects outright.
 *
 * The instance sees Host: typo3.localhost, so the extension's rpId and origin
 * have to name it too — Build/Scripts/runTests.conf writes both.
 */
const SECURE_ALIAS_HOST = 'typo3.localhost';
const isTrustedOrigin = targetUrl.protocol === 'https:'
    || ['localhost', '127.0.0.1', '[::1]'].includes(targetUrl.hostname)
    || targetUrl.hostname.endsWith('.localhost');

export default defineConfig({
    testDir: './Tests/E2E',
    globalSetup: './Tests/E2E/global-setup.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // Serial execution to avoid rate limiting on shared TYPO3 backend
    workers: 1,
    reporter: process.env.CI ? 'github' : 'html',
    timeout: 30_000,

    use: {
        baseURL: isTrustedOrigin ? target : `http://${SECURE_ALIAS_HOST}`,
        launchOptions: {
            args: isTrustedOrigin
                ? []
                : [`--host-resolver-rules=MAP ${SECURE_ALIAS_HOST} ${targetUrl.host}`],
        },
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
