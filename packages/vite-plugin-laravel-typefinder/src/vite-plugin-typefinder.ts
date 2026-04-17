import { exec } from 'node:child_process';
import { promisify } from 'node:util';
import type { Plugin, ResolvedConfig } from 'vite';
import { compileMatcher } from './matcher';
import { Watcher } from './watcher';
import type { Categories } from './types';

const execAsync = promisify(exec);

export interface TypefinderOptions {
    command?: string;
    buildCommand?: string | false;
    watch?: string[];
    debounceMs?: number;
    startupTimeoutMs?: number;
    killTimeoutMs?: number;
}

export const typefinder = ({
    command = 'php artisan typefinder:watch',
    buildCommand = 'php artisan typefinder:generate --json',
    watch,
    debounceMs = 100,
    startupTimeoutMs = 10_000,
    killTimeoutMs = 2_000,
}: TypefinderOptions = {}): Plugin => {
    let resolvedRoot = process.cwd();
    let watcher: Watcher | null = null;
    let matcher: (file: string) => boolean = () => false;
    const pending = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let isBuild = false;

    const scheduleRegen = (): void => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!watcher || watcher.dead) return;
            const batch = Array.from(pending);
            pending.clear();
            if (batch.length === 0) return;
            watcher
                .regen(batch)
                .then((result) => {
                    if (result.changed.length > 0) {
                        process.stderr.write(
                            `[typefinder] regen ${result.changed.length} file(s) in ${result.duration_ms}ms\n`,
                        );
                    }
                    for (const warning of result.warnings) {
                        process.stderr.write(`[typefinder] ${warning}\n`);
                    }
                    for (const failure of result.failed) {
                        process.stderr.write(`[typefinder] ${failure.path}: ${failure.message}\n`);
                    }
                })
                .catch((error: Error) => {
                    process.stderr.write(`[typefinder] regen failed: ${error.message}\n`);
                });
        }, debounceMs);
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

            watcher = await Watcher.spawn({
                command,
                cwd: resolvedRoot,
                startupTimeoutMs,
                killTimeoutMs,
            });

            const paths = watch ?? flattenCategoryPaths(watcher.handshake.categories);
            matcher = compileMatcher(paths);

            watcher.on('died', (reason: string) => {
                process.stderr.write(
                    `[typefinder] watcher exited: ${reason} — restart the dev server\n`,
                );
            });

            server.ws.on('typefinder:full-regen', () => {
                if (watcher && !watcher.dead) {
                    watcher.regen([]).catch((error: Error) => {
                        process.stderr.write(`[typefinder] full regen failed: ${error.message}\n`);
                    });
                }
            });

            server.httpServer?.once('close', () => void watcher?.kill());
            process.once('exit', () => void watcher?.kill());
        },

        async buildStart() {
            if (isBuild) {
                if (buildCommand === false) {
                    process.stderr.write('[typefinder] build-time generation disabled\n');
                    return;
                }
                try {
                    await execAsync(buildCommand, { cwd: resolvedRoot });
                } catch (error) {
                    const message = error instanceof Error ? error.message : String(error);
                    throw new Error(`[typefinder] build-time generation failed: ${message}`, { cause: error });
                }
                return;
            }

            if (watcher && !watcher.dead) {
                const result = await watcher.regen([]);
                if (result.changed.length > 0) {
                    process.stderr.write(
                        `[typefinder] initial regen: ${result.changed.length} file(s) in ${result.duration_ms}ms\n`,
                    );
                }
            }
        },

        handleHotUpdate({ file }) {
            if (isBuild || !watcher || watcher.dead) return;
            if (!matcher(file)) return;
            pending.add(file);
            scheduleRegen();
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
