import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    resolve: {
        alias: {
            // TYPO3 resolves this import map entry at runtime; vitest needs it
            // spelled out so a test can import the shipped login module itself
            // rather than a copy of its logic.
            '@netresearch/nr-passkeys-be': fileURLToPath(
                new URL('./Resources/Public/JavaScript', import.meta.url),
            ),
        },
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
