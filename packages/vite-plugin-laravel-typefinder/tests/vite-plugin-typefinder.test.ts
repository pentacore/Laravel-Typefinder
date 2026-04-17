import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import { EventEmitter } from 'node:events';
import { typefinder } from '../src/vite-plugin-typefinder';
import { Watcher } from '../src/watcher';
import type { Handshake, RegenResponse } from '../src/types';
import type { Plugin, ResolvedConfig, ViteDevServer, HmrContext } from 'vite';

/* ------------------------------------------------------------------ */
/*  Shared helpers                                                     */
/* ------------------------------------------------------------------ */

const HANDSHAKE: Handshake = {
    type: 'ready',
    version: '4.0.2',
    protocol: 1,
    output_path: '/tmp/out',
    categories: {
        models: { enabled: true, paths: ['/app/Models'] },
        enums: { enabled: true, paths: ['/app/Enums'] },
        requests: { enabled: false, paths: [] },
        resources: { enabled: false, paths: [] },
        inertia: { enabled: false, paths: [] },
        broadcasting: { enabled: false, paths: [] },
    },
};

const REGEN_RESPONSE: RegenResponse = {
    type: 'regen.done',
    id: 'r1',
    duration_ms: 42,
    changed: ['models/User.d.ts'],
    warnings: [],
    failed: [],
};

function fakeServer() {
    const httpServer = new EventEmitter();
    return {
        ws: { on: vi.fn() },
        httpServer,
    } as unknown as ViteDevServer;
}

function resolvedConfig(overrides: Partial<ResolvedConfig> = {}): ResolvedConfig {
    return { root: '/project', command: 'serve', ...overrides } as ResolvedConfig;
}

function hooks(plugin: Plugin) {
    return plugin as Plugin & {
        configResolved: (config: ResolvedConfig) => void;
        configureServer: (server: ViteDevServer) => Promise<void>;
        buildStart: () => Promise<void>;
        handleHotUpdate: (ctx: HmrContext) => void;
        closeBundle: () => Promise<void>;
    };
}

/* ------------------------------------------------------------------ */
/*  Serve mode                                                         */
/* ------------------------------------------------------------------ */

describe('typefinder vite plugin – serve mode', () => {
    let spawnSpy: MockInstance;
    let fakeWatcher: Watcher;
    let stderrSpy: MockInstance;

    beforeEach(() => {
        fakeWatcher = Object.assign(new EventEmitter(), {
            handshake: HANDSHAKE,
            dead: false,
            regen: vi.fn<(paths: string[]) => Promise<RegenResponse>>().mockResolvedValue(REGEN_RESPONSE),
            kill: vi.fn<() => Promise<void>>().mockResolvedValue(undefined),
        }) as unknown as Watcher;

        spawnSpy = vi.spyOn(Watcher, 'spawn').mockResolvedValue(fakeWatcher);
        stderrSpy = vi.spyOn(process.stderr, 'write').mockReturnValue(true);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('spawns watcher on configureServer and runs initial regen on buildStart', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());

        expect(spawnSpy).toHaveBeenCalledOnce();
        expect(spawnSpy).toHaveBeenCalledWith(
            expect.objectContaining({ command: 'php artisan typefinder:watch', cwd: '/project' }),
        );

        await plugin.buildStart();
        expect(fakeWatcher.regen).toHaveBeenCalledWith([]);
    });

    it('logs initial regen output to stderr', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        await plugin.buildStart();

        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('initial regen'));
    });

    it('does not log when initial regen has no changes', async () => {
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockResolvedValue({
            ...REGEN_RESPONSE,
            changed: [],
        });

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        await plugin.buildStart();

        expect(stderrSpy).not.toHaveBeenCalledWith(expect.stringContaining('initial regen'));
    });

    it('handleHotUpdate queues matching files for regen', async () => {
        vi.useFakeTimers();
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());

        plugin.handleHotUpdate({ file: '/app/Models/User.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);

        expect(fakeWatcher.regen).toHaveBeenCalledWith(
            expect.arrayContaining(['/app/Models/User.php']),
        );

        vi.useRealTimers();
    });

    it('handleHotUpdate ignores non-matching files', async () => {
        vi.useFakeTimers();
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockClear();

        plugin.handleHotUpdate({ file: '/app/Http/Controllers/Foo.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);

        expect(fakeWatcher.regen).not.toHaveBeenCalled();

        vi.useRealTimers();
    });

    it('custom watch option overrides category paths', async () => {
        vi.useFakeTimers();
        // watch takes directory paths — matcher appends /**/*.php
        const plugin = hooks(typefinder({ watch: ['/custom/dir'] }));
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockClear();

        plugin.handleHotUpdate({ file: '/custom/dir/deep/File.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);
        expect(fakeWatcher.regen).toHaveBeenCalledWith(['/custom/dir/deep/File.php']);

        vi.useRealTimers();
    });

    it('debounces multiple rapid file changes', async () => {
        vi.useFakeTimers();
        const plugin = hooks(typefinder({ debounceMs: 50 }));
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockClear();

        plugin.handleHotUpdate({ file: '/app/Models/A.php' } as HmrContext);
        plugin.handleHotUpdate({ file: '/app/Models/B.php' } as HmrContext);
        plugin.handleHotUpdate({ file: '/app/Models/C.php' } as HmrContext);

        await vi.advanceTimersByTimeAsync(100);

        expect(fakeWatcher.regen).toHaveBeenCalledOnce();
        const paths = (fakeWatcher.regen as ReturnType<typeof vi.fn>).mock.calls[0][0] as string[];
        expect(paths).toHaveLength(3);
        expect(paths).toContain('/app/Models/A.php');
        expect(paths).toContain('/app/Models/B.php');
        expect(paths).toContain('/app/Models/C.php');

        vi.useRealTimers();
    });

    it('logs regen warnings and failures to stderr', async () => {
        vi.useFakeTimers();

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockResolvedValue({
            ...REGEN_RESPONSE,
            warnings: ['field unknown'],
            failed: [{ path: '/app/Models/Bad.php', message: 'boom' }],
        });

        plugin.handleHotUpdate({ file: '/app/Models/User.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);

        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('field unknown'));
        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('boom'));

        vi.useRealTimers();
    });

    it('logs regen error to stderr without crashing', async () => {
        vi.useFakeTimers();

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('regen exploded'));

        plugin.handleHotUpdate({ file: '/app/Models/User.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);

        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('regen failed'));
        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('regen exploded'));

        vi.useRealTimers();
    });

    it('registers typefinder:full-regen websocket handler', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        const server = fakeServer();
        await plugin.configureServer(server);

        expect(server.ws.on).toHaveBeenCalledWith('typefinder:full-regen', expect.any(Function));
    });

    it('full-regen websocket handler triggers regen([])', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        const server = fakeServer();
        await plugin.configureServer(server);

        const wsOnCall = (server.ws.on as ReturnType<typeof vi.fn>).mock.calls.find(
            (c) => c[0] === 'typefinder:full-regen',
        );
        expect(wsOnCall).toBeDefined();
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockClear();
        wsOnCall![1]();

        expect(fakeWatcher.regen).toHaveBeenCalledWith([]);
    });

    it('logs watcher died event to stderr', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());

        fakeWatcher.emit('died', 'signal SIGTERM');

        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('watcher exited'));
        expect(stderrSpy).toHaveBeenCalledWith(expect.stringContaining('signal SIGTERM'));
    });

    it('closeBundle kills the watcher', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());

        await plugin.closeBundle();

        expect(fakeWatcher.kill).toHaveBeenCalledOnce();
    });

    it('closeBundle is no-op when watcher is dead', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        Object.defineProperty(fakeWatcher, 'dead', { value: true });

        await plugin.closeBundle();

        expect(fakeWatcher.kill).not.toHaveBeenCalled();
    });

    it('handleHotUpdate does nothing when watcher is dead', async () => {
        vi.useFakeTimers();
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fakeWatcher.regen as ReturnType<typeof vi.fn>).mockClear();

        Object.defineProperty(fakeWatcher, 'dead', { value: true });

        plugin.handleHotUpdate({ file: '/app/Models/User.php' } as HmrContext);
        await vi.advanceTimersByTimeAsync(200);

        expect(fakeWatcher.regen).not.toHaveBeenCalled();

        vi.useRealTimers();
    });
});

/* ------------------------------------------------------------------ */
/*  Build mode                                                         */
/* ------------------------------------------------------------------ */

// vi.mock factories are hoisted — use vi.hoisted to declare the mock fn
const { mockExec } = vi.hoisted(() => ({ mockExec: vi.fn() }));

vi.mock('node:child_process', async (importOriginal) => {
    const actual = await importOriginal<typeof import('node:child_process')>();
    return { ...actual, exec: mockExec };
});

vi.mock('node:util', async (importOriginal) => {
    const actual = await importOriginal<typeof import('node:util')>();
    return {
        ...actual,
        promisify: (fn: unknown) => {
            if (fn === mockExec) {
                return (...args: unknown[]) =>
                    new Promise((resolve, reject) => {
                        mockExec(...args, (err: Error | null, stdout: string, stderr: string) => {
                            if (err) reject(err);
                            else resolve({ stdout, stderr });
                        });
                    });
            }
            return actual.promisify(fn as (...args: unknown[]) => unknown);
        },
    };
});

describe('typefinder vite plugin – build mode', () => {
    let stderrSpy: MockInstance;

    beforeEach(() => {
        stderrSpy = vi.spyOn(process.stderr, 'write').mockReturnValue(true);
        mockExec.mockImplementation(
            (_cmd: string, _opts: unknown, cb: (err: Error | null, stdout: string, stderr: string) => void) => {
                cb(null, '', '');
            },
        );
    });

    afterEach(() => {
        mockExec.mockReset();
        vi.restoreAllMocks();
    });

    it('runs buildCommand during buildStart in build mode', async () => {
        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        await plugin.buildStart();

        expect(mockExec).toHaveBeenCalledWith(
            'php artisan typefinder:generate --json',
            expect.objectContaining({ cwd: '/project' }),
            expect.any(Function),
        );
    });

    it('custom buildCommand is used', async () => {
        const plugin = hooks(typefinder({ buildCommand: 'php artisan custom:cmd' }));
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        await plugin.buildStart();

        expect(mockExec).toHaveBeenCalledWith(
            'php artisan custom:cmd',
            expect.objectContaining({ cwd: '/project' }),
            expect.any(Function),
        );
    });

    it('buildCommand: false skips generation and logs', async () => {
        const plugin = hooks(typefinder({ buildCommand: false }));
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        await plugin.buildStart();

        expect(mockExec).not.toHaveBeenCalled();
        expect(stderrSpy).toHaveBeenCalledWith(
            expect.stringContaining('build-time generation disabled'),
        );
    });

    it('throws when buildCommand fails', async () => {
        mockExec.mockImplementation(
            (_cmd: string, _opts: unknown, cb: (err: Error | null, stdout: string, stderr: string) => void) => {
                cb(new Error('artisan failed'), '', '');
            },
        );

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        await expect(plugin.buildStart()).rejects.toThrow('build-time generation failed');
    });

    it('does not spawn watcher in build mode', async () => {
        const spawnSpy = vi.spyOn(Watcher, 'spawn');

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        await plugin.configureServer(fakeServer());

        expect(spawnSpy).not.toHaveBeenCalled();
    });

    it('handleHotUpdate is no-op in build mode', async () => {
        const spawnSpy = vi.spyOn(Watcher, 'spawn');

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig({ command: 'build' } as Partial<ResolvedConfig>));

        plugin.handleHotUpdate({ file: '/app/Models/User.php' } as HmrContext);

        expect(spawnSpy).not.toHaveBeenCalled();
    });
});

/* ------------------------------------------------------------------ */
/*  flattenCategoryPaths                                               */
/* ------------------------------------------------------------------ */

describe('flattenCategoryPaths', () => {
    it('uses only enabled category paths for matching', async () => {
        vi.useFakeTimers();

        const fw = Object.assign(new EventEmitter(), {
            handshake: HANDSHAKE,
            dead: false,
            regen: vi.fn<(paths: string[]) => Promise<RegenResponse>>().mockResolvedValue({
                ...REGEN_RESPONSE,
                changed: [],
            }),
            kill: vi.fn<() => Promise<void>>().mockResolvedValue(undefined),
        }) as unknown as Watcher;

        vi.spyOn(Watcher, 'spawn').mockResolvedValue(fw);
        vi.spyOn(process.stderr, 'write').mockReturnValue(true);

        const plugin = hooks(typefinder());
        plugin.configResolved(resolvedConfig());
        await plugin.configureServer(fakeServer());
        (fw.regen as ReturnType<typeof vi.fn>).mockClear();

        // Models enabled → should match
        plugin.handleHotUpdate({ file: '/app/Models/Foo.php' } as HmrContext);
        // Enums enabled → should match
        plugin.handleHotUpdate({ file: '/app/Enums/Bar.php' } as HmrContext);
        // Resources disabled → should not match
        plugin.handleHotUpdate({ file: '/app/Http/Resources/Baz.php' } as HmrContext);

        await vi.advanceTimersByTimeAsync(200);

        expect(fw.regen).toHaveBeenCalledOnce();
        const paths = (fw.regen as ReturnType<typeof vi.fn>).mock.calls[0][0] as string[];
        expect(paths).toContain('/app/Models/Foo.php');
        expect(paths).toContain('/app/Enums/Bar.php');
        expect(paths).not.toContain('/app/Http/Resources/Baz.php');

        vi.useRealTimers();
        vi.restoreAllMocks();
    });
});
