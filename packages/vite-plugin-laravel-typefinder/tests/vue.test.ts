import { afterEach, describe, expect, it } from 'vitest';
import { createRequire } from 'node:module';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadVueTypeCacheInvalidator } from '../src/vue';

describe('loadVueTypeCacheInvalidator', () => {
    const roots: string[] = [];

    const project = (compilerSource: string | null): string => {
        const root = mkdtempSync(join(tmpdir(), 'typefinder-vue-'));
        roots.push(root);
        writeFileSync(join(root, 'package.json'), '{}');
        if (compilerSource !== null) {
            mkdirSync(join(root, 'node_modules/vue/compiler-sfc'), { recursive: true });
            writeFileSync(join(root, 'node_modules/vue/package.json'), '{"name":"vue"}');
            writeFileSync(join(root, 'node_modules/vue/compiler-sfc/index.js'), compilerSource);
        }
        return root;
    };

    afterEach(() => {
        for (const root of roots.splice(0)) rmSync(root, { recursive: true, force: true });
    });

    it("calls the project's invalidateTypeCache", () => {
        const root = project('exports.calls = []; exports.invalidateTypeCache = (file) => exports.calls.push(file);');

        const invalidate = loadVueTypeCacheInvalidator(root);
        invalidate?.('/out/pages.d.ts');

        const compiler = createRequire(join(root, 'package.json'))('vue/compiler-sfc') as { calls: string[] };
        expect(compiler.calls).toEqual(['/out/pages.d.ts']);
    });

    it('is null without Vue', () => {
        expect(loadVueTypeCacheInvalidator(project(null))).toBeNull();
    });

    it('is null when the compiler has no invalidateTypeCache (Vue 2)', () => {
        expect(loadVueTypeCacheInvalidator(project('exports.parse = () => null;'))).toBeNull();
    });
});
