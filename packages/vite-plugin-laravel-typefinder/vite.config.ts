import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';
import {codecovVitePlugin} from "@codecov/vite-plugin";

export default defineConfig({
    plugins: [
        dts({
            rollupTypes: true,
            tsconfigPath: './tsconfig.json',
        }),
        codecovVitePlugin({
            enableBundleAnalysis: process.env.CODECOV_TOKEN !== undefined,
            bundleName: "vite-plugin-laravel-typefinder",
            uploadToken: process.env.CODECOV_TOKEN,
        }),
    ],
    build: {
        lib: {
            entry: resolve(__dirname, 'src/index.ts'),
            formats: ['es'],
            fileName: 'index',
        },
        rollupOptions: {
            external: ['node:child_process', 'node:events', 'node:path', 'node:stream', 'node:util', 'vite', 'rollup', 'picomatch'],
        },
    },
    test: {
        environment: 'node',
        testTimeout: 15_000,
        include: ['tests/**/*.test.ts'],
    },
});
