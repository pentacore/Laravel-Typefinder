import { defineBuildConfig } from 'unbuild';

export default defineBuildConfig({
    clean: true,
    declaration: true,
    externals: ['rollup', 'vite'],
    failOnWarn: false,
    // ESM-only: Vite itself is ESM-only, and emitting CJS creates
    // dual-package hazards for zero user benefit.
    rollup: {
        emitCJS: false,
    },
});
