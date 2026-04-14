import { exec } from 'child_process';
import { minimatch } from 'minimatch';
import path from 'path';
import type { PluginContext } from 'rollup';
import { promisify } from 'util';
import type { HmrContext, Plugin } from 'vite';

const execAsync = promisify(exec);

/**
 * Options for the `typefinder` Vite plugin.
 *
 * All fields are optional — the defaults work for a standard Laravel project
 * running PHP directly. Override `command` when using Sail, Herd, Docker, or
 * any other PHP runtime wrapper. Extend `watch` when you use Typefinder's
 * resource / page / broadcast features so the plugin regenerates on those
 * file changes too.
 */
export interface TypefinderOptions {
    /**
     * Glob patterns (relative to the Vite project root) whose changes should
     * trigger a regeneration. Matched against each HMR update's file path.
     *
     * The default covers models, enums, and form requests — the always-on
     * Typefinder categories. If you enable optional categories (Inertia pages,
     * broadcasting) or use JSON resources, extend the list so updates on
     * those directories also re-run the generator.
     *
     * @default ['app/Models/** /*.php', 'app/Enums/** /*.php', 'app/Http/Requests/** /*.php']
     */
    watch?: string[];

    /**
     * Shell command used to regenerate types. Customize for non-standard
     * PHP runtimes:
     *
     * - Sail: `'./vendor/bin/sail artisan typefinder:generate'`
     * - Herd: `'herd php artisan typefinder:generate'`
     * - Docker: `'docker compose exec app php artisan typefinder:generate'`
     *
     * @default 'php artisan typefinder:generate'
     */
    command?: string;

    /**
     * Debounce window in milliseconds. When multiple files change within this
     * window the plugin coalesces them into a single regeneration. Keep small
     * for fast feedback; raise if your editor triggers noisy saves.
     *
     * @default 100
     */
    debounceMs?: number;
}

/**
 * Vite plugin that runs `php artisan typefinder:generate` automatically —
 * once at `buildStart`, and again on every HMR update whose file path
 * matches any pattern in {@link TypefinderOptions.watch}.
 *
 * Install the Composer package `pentacore/laravel-typefinder` in your Laravel
 * app, then register this plugin in `vite.config.ts`:
 *
 * ```ts
 * import { defineConfig } from 'vite';
 * import laravel from 'laravel-vite-plugin';
 * import typefinder from '@pentacore/vite-plugin-laravel-typefinder';
 *
 * export default defineConfig({
 *   plugins: [
 *     laravel({ input: ['resources/js/app.ts'] }),
 *     typefinder(),
 *   ],
 * });
 * ```
 *
 * Regenerations are debounced and serialized — concurrent triggers coalesce
 * into at most one in-flight run plus one queued run. The plugin uses Vite's
 * `enforce: 'pre'` ordering so types are fresh before other plugins inspect
 * them.
 *
 * @param options Plugin options. See {@link TypefinderOptions}.
 * @returns A Vite plugin.
 */
export const typefinder = ({
    watch = [
        'app/Models/**/*.php',
        'app/Enums/**/*.php',
        'app/Http/Requests/**/*.php',
    ],
    command = 'php artisan typefinder:generate',
    debounceMs = 100,
}: TypefinderOptions = {}): Plugin => {
    // Normalize path separators
    const patterns = watch.map((pattern) => pattern.replace(/\\/g, '/'));

    let timer: NodeJS.Timeout | undefined;
    let running = false;
    let queued = false;

    const runCommand = async (ctx: PluginContext): Promise<void> => {
        if (running) {
            queued = true;
            return;
        }

        running = true;

        try {
            await execAsync(command);
        } catch (error) {
            ctx.error('Error generating types: ' + error);
        } finally {
            running = false;

            if (queued) {
                queued = false;
                await runCommand(ctx);
            }
        }

        ctx.info('Typefinder: TypeScript types generated');
    };

    const scheduleRun = (ctx: PluginContext): void => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            void runCommand(ctx);
        }, debounceMs);
    };

    return {
        name: '@pentacore/vite-plugin-laravel-typefinder',
        enforce: 'pre',

        buildStart(this: PluginContext) {
            return runCommand(this);
        },

        async handleHotUpdate(this: PluginContext, { file, server }: Pick<HmrContext, 'file' | 'server'>) {
            if (shouldRun(patterns, { file, server })) {
                scheduleRun(this);
            }
        },
    };
};

/**
 * Decide whether a given HMR file event matches any of the configured
 * watch patterns. Patterns are resolved relative to the Vite project root.
 *
 * @internal
 */
const shouldRun = (
    patterns: string[],
    opts: Pick<HmrContext, 'file' | 'server'>,
): boolean => {
    const file = opts.file.replace(/\\/g, '/');

    return patterns.some((pattern) => {
        const resolved = path
            .resolve(opts.server.config.root, pattern)
            .replace(/\\/g, '/');

        return minimatch(file, resolved);
    });
};
