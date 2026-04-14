import { exec } from 'child_process';
import { minimatch } from 'minimatch';
import path from 'path';
import type { PluginContext } from 'rollup';
import { promisify } from 'util';
import type { HmrContext, Plugin } from 'vite';

const execAsync = promisify(exec);

export interface TypefinderOptions {
    /**
     * Glob patterns for PHP files to watch for changes.
     * @default ['app/Models/\*\*\/\*.php', 'app/Enums/\*\*\/\*.php', 'app/Http/Requests/\*\*\/\*.php']
     */
    watch?: string[];

    /**
     * The artisan command to run for type generation.
     * Customize for Sail, Herd, Docker, etc.
     * @default 'php artisan typefinder:generate'
     */
    command?: string;

    /**
     * Debounce window for filesystem changes in milliseconds.
     * @default 100
     */
    debounceMs?: number;
}

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
