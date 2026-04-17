import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'node',
        testTimeout: 15_000,
        include: ['tests/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['src/**/*.ts'],
        },
    },
});
