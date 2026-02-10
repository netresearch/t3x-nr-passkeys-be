import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E test configuration for nr_passkeys_be TYPO3 extension.
 *
 * Tests run against a DDEV TYPO3 instance.
 * Start DDEV first: `make up` or `ddev start && ddev install-v13`
 */
export default defineConfig({
    testDir: './Tests/E2E',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // Serial execution to avoid rate limiting on shared TYPO3 backend
    workers: 1,
    reporter: process.env.CI ? 'github' : 'html',
    timeout: 30_000,

    use: {
        baseURL: process.env.TYPO3_BASE_URL || 'https://v13.nr-passkeys-be.ddev.site',
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
