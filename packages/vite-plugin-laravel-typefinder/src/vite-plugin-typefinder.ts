import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import type { Plugin, ResolvedConfig } from 'vite';
import { compileMatcher } from './matcher';
import { PathMapper, realpathLoose } from './paths';
import { Watcher } from './watcher';
import type { Categories, RegenResponse } from './types';

const execAsync = promisify(exec);

export interface TypefinderOptions {
    command?: string;
    buildCommand?: string | false;
    watch?: string[];
    debounceMs?: number;
    startupTimeoutMs?: number;
    killTimeoutMs?: number;
    /** How long to wait for one regen before reporting it failed; 0 disables the timeout. */
    regenTimeoutMs?: number;
    /**
     * Maps a path prefix as the watcher sees it (inside a Sail / Docker
     * container) to the same directory on the host Vite runs on, e.g.
     * `{ '/var/www/html': process.cwd() }`. Applied both ways.
     */
    pathMap?: Record<string, string>;
    /**
     * Directories whose `.php` files change the database schema. A change
     * there triggers a full regen, since any model may be affected.
     * Defaults to `database/migrations` under the Vite root; `false` disables it.
     */
    migrations?: string[] | false;
}

const log = (message: string): void => {
    process.stderr.write(`[typefinder] ${message}\n`);
};

const reportRegen = (result: RegenResponse, label: string): void => {
    if (result.changed.length > 0) {
        log(`${label} ${result.changed.length} file(s) in ${result.duration_ms}ms`);
    }
    for (const warning of result.warnings) {
        log(warning);
    }
    for (const failure of result.failed) {
        log(`${failure.path}: ${failure.message}`);
    }
};

interface GenerateJson {
    success: boolean;
    warnings?: string[];
    errors?: string[];
}

/**
 * The JSON summary `typefinder:generate --json` prints as its last stdout
 * line, or null when stdout holds none.
 */
const parseGenerateJson = (stdout: unknown): GenerateJson | null => {
    if (typeof stdout !== 'string') return null;
    const lines = stdout.split('\n').map((l) => l.trim()).filter((l) => l !== '');
    for (let i = lines.length - 1; i >= 0; i--) {
        try {
            const parsed = JSON.parse(lines[i]!) as unknown;
            if (parsed !== null && typeof parsed === 'object' && 'success' in parsed) {
                return parsed as GenerateJson;
            }
        } catch {
            // not the JSON line
        }
    }
    return null;
};

export const typefinder = ({
    command = 'php artisan typefinder:watch',
    buildCommand = 'php artisan typefinder:generate --json',
    watch,
    debounceMs = 100,
    startupTimeoutMs = 10_000,
    killTimeoutMs = 2_000,
    regenTimeoutMs = 120_000,
    pathMap,
    migrations,
}: TypefinderOptions = {}): Plugin => {
    let resolvedRoot = process.cwd();
    let watcher: Watcher | null = null;
    let matcher: (file: string) => boolean = () => false;
    let migrationMatcher: (file: string) => boolean = () => false;
    let fullPending = false;
    let mapper = new PathMapper();
    const pending = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let isBuild = false;

    const scheduleRegen = (): void => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!watcher || watcher.dead) return;
            // Only scheduled right after a file was queued or a full regen
            // was asked for, so there is always something to send. A full
            // regen covers every pending file too.
            const batch = fullPending ? [] : Array.from(pending);
            const label = fullPending ? 'full regen' : 'regen';
            pending.clear();
            fullPending = false;
            watcher
                .regen(batch)
                .then((result) => reportRegen(result, label))
                .catch((error: Error) => {
                    log(`regen failed: ${error.message}`);
                });
        }, debounceMs);
    };

    const onFileEvent = (file: string): void => {
        if (isBuild || !watcher || watcher.dead) return;
        // PHP resolves symlinks, so compare and send real paths.
        const real = realpathLoose(file);
        if (migrationMatcher(real)) {
            fullPending = true;
            scheduleRegen();
            return;
        }
        if (!matcher(real)) return;
        pending.add(mapper.toWatcher(real));
        scheduleRegen();
    };

    return {
        name: '@pentacore/vite-plugin-laravel-typefinder',
        enforce: 'pre',

        configResolved(config: ResolvedConfig) {
            resolvedRoot = config.root;
            isBuild = config.command === 'build';
        },

        async configureServer(server) {
            if (isBuild) return;

            mapper = new PathMapper(pathMap);

            watcher = await Watcher.spawn({
                command,
                cwd: resolvedRoot,
                startupTimeoutMs,
                killTimeoutMs,
                regenTimeoutMs,
            });

            const paths = watch ?? flattenCategoryPaths(watcher.handshake.categories).map((p) => mapper.toHost(p));
            matcher = compileMatcher(paths.map(realpathLoose));
            const migrationPaths = migrations === false
                ? []
                : migrations ?? [`${resolvedRoot}/database/migrations`];
            migrationMatcher = compileMatcher(migrationPaths.map(realpathLoose));

            watcher.on('died', (reason: string) => {
                log(`watcher exited: ${reason} — restart the dev server`);
            });

            // Created and deleted files never reach `handleHotUpdate` on
            // Vite 8, so listen to the file watcher directly.
            server.watcher.on('add', onFileEvent);
            server.watcher.on('change', onFileEvent);
            server.watcher.on('unlink', onFileEvent);

            server.ws.on('typefinder:full-regen', () => {
                if (watcher && !watcher.dead) {
                    watcher
                        .regen([])
                        .then((result) => reportRegen(result, 'full regen'))
                        .catch((error: Error) => {
                            log(`full regen failed: ${error.message}`);
                        });
                }
            });

            server.httpServer?.once('close', () => void watcher?.kill());
            process.once('exit', () => void watcher?.kill());
        },

        async buildStart() {
            if (isBuild) {
                if (buildCommand === false) {
                    log('build-time generation disabled');
                    return;
                }
                let stdout: unknown;
                try {
                    ({ stdout } = await execAsync(buildCommand, { cwd: resolvedRoot }));
                } catch (error) {
                    const summary = parseGenerateJson((error as { stdout?: unknown }).stdout);
                    for (const warning of summary?.warnings ?? []) {
                        log(warning);
                    }
                    const errors = summary?.errors ?? [];
                    const message = errors.length > 0
                        ? errors.join('\n')
                        : error instanceof Error ? error.message : String(error);
                    throw new Error(`[typefinder] build-time generation failed: ${message}`, { cause: error });
                }
                for (const warning of parseGenerateJson(stdout)?.warnings ?? []) {
                    log(warning);
                }
                return;
            }

            if (watcher && !watcher.dead) {
                reportRegen(await watcher.regen([]), 'initial regen:');
            }
        },

        async closeBundle() {
            if (watcher && !watcher.dead) {
                await watcher.kill();
            }
        },
    };
};

const flattenCategoryPaths = (categories: Categories): string[] => {
    const out: string[] = [];
    for (const name of Object.keys(categories) as Array<keyof Categories>) {
        const entry = categories[name];
        if (entry.enabled) {
            out.push(...entry.paths);
        }
    }
    return out;
};

export default typefinder;
