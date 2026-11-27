import { createRequire } from 'node:module';
import { join } from 'node:path';

/**
 * Vue's `invalidateTypeCache()` from the project's own `vue/compiler-sfc`,
 * or null when Vue is not installed. Loaded the way `@vitejs/plugin-vue`
 * loads the compiler (a `require` resolved from the Vite root), so it clears
 * the cache that plugin compiles with.
 */
export const loadVueTypeCacheInvalidator = (root: string): ((file: string) => void) | null => {
    try {
        const compiler = createRequire(join(root, 'package.json'))('vue/compiler-sfc') as {
            invalidateTypeCache?: unknown;
        };
        const invalidate = compiler.invalidateTypeCache;
        return typeof invalidate === 'function' ? (file: string) => void invalidate(file) : null;
    } catch {
        return null;
    }
};
