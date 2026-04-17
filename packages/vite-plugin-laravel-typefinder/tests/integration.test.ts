import { describe, expect, it } from 'vitest';
import { resolve } from 'node:path';
import { rmSync } from 'node:fs';
import { Watcher } from '../src/watcher';

const repoRoot = resolve(__dirname, '../../..');

describe('typefinder:watch end-to-end', () => {
    it('handshake → full regen → clean shutdown', async () => {
        const watcher = await Watcher.spawn({
            command: `${repoRoot}/vendor/bin/testbench typefinder:watch`,
            cwd: repoRoot,
            startupTimeoutMs: 15_000,
            killTimeoutMs: 3_000,
        });

        try {
            expect(watcher.handshake.type).toBe('ready');
            expect(watcher.handshake.protocol).toBe(1);
            expect(watcher.handshake.categories.models.enabled).toBe(true);

            // Delete the previous output so the regen always produces changed files.
            rmSync(watcher.handshake.output_path, { recursive: true, force: true });

            const response = await watcher.regen([]);
            expect(response.type).toBe('regen.done');
            expect(response.changed.length).toBeGreaterThan(0);
            expect(response.duration_ms).toBeGreaterThanOrEqual(0);
        } finally {
            await watcher.kill();
        }

        expect(watcher.dead).toBe(true);
    }, 30_000);
});
