import { Plugin } from 'vite';

/**
 * Options for the `typefinder` Vite plugin.
 *
 * All fields are optional — the defaults work for a standard Laravel project
 * running PHP directly. Override `command` when using Sail, Herd, Docker, or
 * any other PHP runtime wrapper. Extend `watch` when you use Typefinder's
 * resource / page / broadcast features so the plugin regenerates on those
 * file changes too.
 */
interface TypefinderOptions {
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
declare const typefinder: ({ watch, command, debounceMs, }?: TypefinderOptions) => Plugin;

export { typefinder as default, typefinder };
export type { TypefinderOptions };
