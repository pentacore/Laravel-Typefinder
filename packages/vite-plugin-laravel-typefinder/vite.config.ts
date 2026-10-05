import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import dts from 'vite-plugin-dts';

export default defineConfig({
    plugins: [
        dts({
            // rollupTypes: true,
            tsconfigPath: './tsconfig.json',
        }),
    ],
    build: {
        lib: {
            entry: resolve(import.meta.dirname, 'src/index.ts'),
            formats: ['es'],
            fileName: 'index',
        },
        rolldownOptions: {
            external: ['node:child_process', 'node:events', 'node:path', 'node:stream', 'node:util', 'vite', 'rollup', 'picomatch'],
        },
    },
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
