import { Plugin } from 'vite';

interface TypefinderOptions {
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
declare const typefinder: ({ watch, command, debounceMs, }?: TypefinderOptions) => Plugin;

export { typefinder as default, typefinder };
export type { TypefinderOptions };
