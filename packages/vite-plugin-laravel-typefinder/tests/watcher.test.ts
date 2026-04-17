import { describe, expect, it, vi } from 'vitest';
import { EventEmitter } from 'node:events';
import { PassThrough } from 'node:stream';
import type { ChildProcess } from 'node:child_process';
import { Watcher } from '../src/watcher';
import type { Handshake, RegenResponse } from '../src/types';

const fakeChild = () => {
    const child = new EventEmitter() as ChildProcess & {
        stdout: PassThrough;
        stderr: PassThrough;
        stdin: PassThrough;
        kill: ReturnType<typeof vi.fn>;
    };
    child.stdout = new PassThrough();
    child.stderr = new PassThrough();
    child.stdin = new PassThrough();
    child.kill = vi.fn(() => true);
    return child;
};

const HANDSHAKE: Handshake = {
    type: 'ready',
    version: '4.0.2',
    protocol: 1,
    output_path: '/tmp/out',
    categories: {
        models: { enabled: true, paths: ['/tmp/app/Models'] },
        enums: { enabled: true, paths: [] },
        requests: { enabled: true, paths: [] },
        resources: { enabled: false, paths: [] },
        inertia: { enabled: false, paths: [] },
        broadcasting: { enabled: false, paths: [] },
    },
};

function spawnAndHandshake(child: ReturnType<typeof fakeChild>): Promise<Watcher> {
    const promise = Watcher.spawn({
        command: 'php artisan typefinder:watch',
        cwd: '/tmp',
        startupTimeoutMs: 5_000,
        spawnFn: () => child as unknown as ChildProcess,
    });
    child.stdout.write(JSON.stringify(HANDSHAKE) + '\n');
    return promise;
}

describe('Watcher.spawn', () => {
    it('resolves when child emits valid ready line', async () => {
        const child = fakeChild();
        const watcher = await spawnAndHandshake(child);

        expect(watcher.handshake).toEqual(HANDSHAKE);
        expect(watcher.dead).toBe(false);
    });

    it('rejects if handshake is malformed', async () => {
        const child = fakeChild();
        const promise = Watcher.spawn({
            command: 'php artisan typefinder:watch',
            cwd: '/tmp',
            startupTimeoutMs: 5_000,
            spawnFn: () => child as unknown as ChildProcess,
        });

        child.stdout.write('not json at all\n');

        await expect(promise).rejects.toThrow('malformed handshake');
        expect(child.kill).toHaveBeenCalledWith('SIGTERM');
    });

    it('rejects if child exits before handshake', async () => {
        const child = fakeChild();
        const promise = Watcher.spawn({
            command: 'php artisan typefinder:watch',
            cwd: '/tmp',
            startupTimeoutMs: 5_000,
            spawnFn: () => child as unknown as ChildProcess,
        });

        child.emit('exit', 1, null);

        await expect(promise).rejects.toThrow('watcher exited before handshake');
    });
});

describe('Watcher.regen', () => {
    it('writes request to stdin and resolves on matching response', async () => {
        const child = fakeChild();
        const stdinWrites: string[] = [];
        child.stdin.write = (chunk: unknown) => {
            stdinWrites.push(String(chunk));
            return true;
        };

        const watcher = await spawnAndHandshake(child);

        const regenPromise = watcher.regen(['/app/Models/User.php']);

        expect(stdinWrites).toHaveLength(1);
        const req = JSON.parse(stdinWrites[0]!);
        expect(req.type).toBe('regen');
        expect(req.paths).toEqual(['/app/Models/User.php']);

        const response: RegenResponse = {
            type: 'regen.done',
            id: req.id,
            duration_ms: 42,
            changed: ['User'],
            warnings: [],
            failed: [],
        };
        child.stdout.write(JSON.stringify(response) + '\n');

        const result = await regenPromise;
        expect(result.duration_ms).toBe(42);
        expect(result.changed).toEqual(['User']);
    });

    it('coalesces concurrent calls into one batched request', async () => {
        const child = fakeChild();
        const stdinWrites: string[] = [];
        child.stdin.write = (chunk: unknown) => {
            stdinWrites.push(String(chunk));
            return true;
        };

        const watcher = await spawnAndHandshake(child);

        // First call goes in-flight immediately
        const p1 = watcher.regen(['/a.php']);
        // These two get queued and merged
        const p2 = watcher.regen(['/b.php']);
        const p3 = watcher.regen(['/c.php']);

        expect(stdinWrites).toHaveLength(1);
        const req1 = JSON.parse(stdinWrites[0]!);

        // Respond to first request
        child.stdout.write(
            JSON.stringify({
                type: 'regen.done',
                id: req1.id,
                duration_ms: 10,
                changed: ['A'],
                warnings: [],
                failed: [],
            } satisfies RegenResponse) + '\n',
        );

        const r1 = await p1;
        expect(r1.changed).toEqual(['A']);

        // After first resolves, queued batch should have been dispatched
        expect(stdinWrites).toHaveLength(2);
        const req2 = JSON.parse(stdinWrites[1]!);
        expect(req2.paths).toEqual(expect.arrayContaining(['/b.php', '/c.php']));
        expect(req2.paths).toHaveLength(2);

        // Respond to second (batched) request
        child.stdout.write(
            JSON.stringify({
                type: 'regen.done',
                id: req2.id,
                duration_ms: 20,
                changed: ['B', 'C'],
                warnings: [],
                failed: [],
            } satisfies RegenResponse) + '\n',
        );

        const [r2, r3] = await Promise.all([p2, p3]);
        expect(r2.changed).toEqual(['B', 'C']);
        expect(r3.changed).toEqual(['B', 'C']);
    });

    it('rejects after kill', async () => {
        const child = fakeChild();
        const watcher = await spawnAndHandshake(child);

        // Simulate child exiting on kill
        child.kill.mockImplementation(() => {
            child.emit('exit', null, 'SIGTERM');
            return true;
        });

        await watcher.kill();
        expect(watcher.dead).toBe(true);

        await expect(watcher.regen(['/foo.php'])).rejects.toThrow('watcher is dead');
    });
});
