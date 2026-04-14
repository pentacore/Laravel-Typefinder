/**
 * @pentacore/vite-plugin-laravel-typefinder
 *
 * Vite plugin that keeps your Laravel Typefinder `.d.ts` files in sync with
 * the PHP side by running `php artisan typefinder:generate` at build start
 * and on matching HMR file changes.
 *
 * Pairs with the `pentacore/laravel-typefinder` Composer package.
 *
 * @example
 * import typefinder from '@pentacore/vite-plugin-laravel-typefinder';
 *
 * export default defineConfig({
 *   plugins: [typefinder()],
 * });
 */
export { typefinder, type TypefinderOptions } from './vite-plugin-typefinder';
export { typefinder as default } from './vite-plugin-typefinder';
