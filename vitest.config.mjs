import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    resolve: {
        alias: [
            // TYPO3 resolves this import map entry at runtime; vitest needs it
            // spelled out so a test can import the shipped login module itself
            // rather than a copy of its logic.
            {
                find: '@netresearch/nr-passkeys-be',
                replacement: fileURLToPath(new URL('./Resources/Public/JavaScript', import.meta.url)),
            },
            // Core modules exist only in a running backend; see the stub.
            {
                find: /^@typo3\/.*$/,
                replacement: fileURLToPath(new URL('./Tests/JavaScript/Stubs/typo3.js', import.meta.url)),
            },
        ],
    },
    test: {
        include: ['Tests/JavaScript/**/*.test.{js,ts}'],
        environment: 'jsdom',
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['Resources/Public/JavaScript/**/*.js'],
        },
    },
});
