import { defineConfig } from 'vitest/config';

export default defineConfig({
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
