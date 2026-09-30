import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.js'],
            exclude: ['resources/js/**/*.test.js', 'resources/js/app.js', 'resources/js/bootstrap.js'],
            reporter: ['text', 'json-summary', 'lcov'],
            reportsDirectory: 'coverage/frontend',
            thresholds: {
                statements: 91,
                branches: 72,
                functions: 81,
                lines: 91,
            },
        },
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        restoreMocks: true,
    },
});
