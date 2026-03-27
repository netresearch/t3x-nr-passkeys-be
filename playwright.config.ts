import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E test configuration for nr_passkeys_be TYPO3 extension.
 *
 * Tests run against a TYPO3 instance. Options:
 *   - CI: uses typo3-ci-workflows reusable E2E workflow (PHP built-in server)
 *   - Local: `./Build/Scripts/runTests.sh e2e` or set TYPO3_BASE_URL manually
 */
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
        baseURL: process.env.TYPO3_BASE_URL || 'http://localhost:8080',
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
