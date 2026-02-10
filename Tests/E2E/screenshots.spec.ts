import { test, Page } from '@playwright/test';

const ADMIN_USER = process.env.TYPO3_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.TYPO3_ADMIN_PASS || 'Joh316!!';
const SCREENSHOT_DIR = 'Documentation/Images';

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

test.describe('Documentation Screenshots', () => {
    test('login page with passkey button', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        // Wait for passkey UI injection
        await page.waitForSelector('#passkey-login-container', { timeout: 5000 }).catch(() => {});

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/Login/LoginPageWithPasskey.png`,
            fullPage: false,
        });
    });

    test('login page with username filled', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        await page.waitForSelector('#passkey-login-container', { timeout: 5000 }).catch(() => {});
        await page.locator('#t3-username').fill('admin');

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/Login/LoginPageUsernameFirst.png`,
            fullPage: false,
        });
    });

    test('login page passkey error message', async ({ page }) => {
        await page.goto('/typo3/login');
        await page.waitForLoadState('networkidle');

        await page.waitForSelector('#passkey-login-container', { timeout: 5000 }).catch(() => {});

        // Simulate the error display
        await page.evaluate(() => {
            const errorEl = document.getElementById('passkey-error');
            if (errorEl) {
                errorEl.textContent = 'Passkey authentication failed. Your passkey was not accepted. Please try again or sign in with your password.';
                errorEl.classList.remove('d-none');
            }
        });

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/Login/LoginPagePasskeyError.png`,
            fullPage: false,
        });
    });

    test('user settings passkey management', async ({ page }) => {
        const loggedIn = await loginAsAdmin(page);
        test.skip(!loggedIn, 'Login failed');

        await page.goto('/typo3/module/user/setup');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);

        // Content is inside the list_frame iframe
        const contentFrame = page.frame('list_frame') ?? page;

        // Click the "Account security" tab
        const securityTab = contentFrame.locator(
            'text=Account security',
        );
        if (await securityTab.isVisible({ timeout: 3000 }).catch(() => false)) {
            await securityTab.click();
            await page.waitForTimeout(500);
        }

        // Scroll to show the Passkeys section centered in view
        await contentFrame.evaluate(() => {
            const passkeys = Array.from(
                document.querySelectorAll('h4, h3, legend, label, strong'),
            ).find(e => e.textContent?.includes('Passkeys'));
            if (passkeys) {
                passkeys.scrollIntoView({ block: 'center' });
            }
        });
        await page.waitForTimeout(300);

        await page.screenshot({
            path: `${SCREENSHOT_DIR}/UserSettings/PasskeyManagement.png`,
            fullPage: false,
        });
    });
});
