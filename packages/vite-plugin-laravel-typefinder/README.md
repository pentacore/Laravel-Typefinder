# @pentacore/vite-plugin-laravel-typefinder

A Vite plugin that keeps your TypeScript type definitions in sync with your Laravel codebase. In development, it spawns a persistent `typefinder:watch` process that regenerates only the `.d.ts` files affected by each edit — typical latency is 20-60ms per change. In production builds, it runs a one-shot `typefinder:generate` before bundling.

> **This plugin is a companion to the [`pentacore/laravel-typefinder`](https://packagist.org/packages/pentacore/laravel-typefinder) Composer package — you must install that first.**

## Requirements

- The Composer package `pentacore/laravel-typefinder` installed and auto-discovered in your Laravel app.
- Vite 6+
- Node 18+
- ESM-only — the package exposes only `dist/index.mjs`. Consumers must `import` (not `require`) it. Any modern Vite/Laravel app already meets this.

## Installation

```bash
composer require pentacore/laravel-typefinder
npm i -D @pentacore/vite-plugin-laravel-typefinder
```

## Usage

```ts
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import typefinder from '@pentacore/vite-plugin-laravel-typefinder';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.js'] }),
        typefinder(),   // zero-config — all options are optional
    ],
});
```

## Configuration

All options are optional. Defaults work for a standard Laravel project with `php` on `$PATH`.

```ts
typefinder({
    // Shell command for the long-lived watcher process.
    // Override for Sail/Herd/Docker.
    command: 'php artisan typefinder:watch',

    // Shell command used in `vite build` (one-shot, no watcher).
    // Set to `false` to skip generation entirely in build mode.
    buildCommand: 'php artisan typefinder:generate --json',

    // Override the paths the plugin watches. When unset (the default),
    // paths come from the watcher's handshake, which mirrors your
    // config/typefinder.php. Provide absolute paths.
    watch: undefined,

    // Debounce window (ms) for coalescing HMR events.
    debounceMs: 100,

    // How long to wait for the watcher's `ready` handshake before failing.
    startupTimeoutMs: 10_000,

    // Grace period between SIGTERM and SIGKILL when shutting down.
    killTimeoutMs: 2_000,
});
```

### Runtime-specific examples

```ts
// Laravel Sail
typefinder({ command: './vendor/bin/sail artisan typefinder:watch' })

// Laravel Herd
typefinder({ command: 'herd php artisan typefinder:watch' })

// Docker Compose
typefinder({ command: 'docker compose exec app php artisan typefinder:watch' })
```

## Behavior

**Development (`vite dev`):** The plugin spawns `typefinder:watch` — a persistent Laravel process that boots once and accepts incremental regeneration requests over NDJSON. When a `.php` file matching the watched paths changes via HMR, the plugin sends the changed file paths to the watcher, which re-extracts only those files and rewrites only the affected `.d.ts` outputs. Multiple rapid edits within the debounce window are coalesced into a single request.

The watched paths are read from the watcher's startup handshake, which mirrors your `config/typefinder.php`. If you enable Inertia pages or broadcasting in the config, the plugin automatically watches those directories too — no manual `watch` array needed.

**Production (`vite build`):** No watcher. The plugin runs `typefinder:generate --json` once in `buildStart` and fails the build on non-zero exit. Set `buildCommand: false` to skip generation entirely (e.g. for pre-built containers without a PHP runtime).

**Shutdown:** The watcher is killed on `closeBundle` and on process exit. If the watcher dies mid-session (PHP fatal, OOM), the plugin logs the error and stops regenerating — restart the dev server to recover.

### Migration from v4.0

- `command` default changed from `typefinder:generate` to `typefinder:watch`. If you explicitly set `command`, update it — dev mode requires the long-lived watcher.
- `watch` default changed from a hardcoded path list to `undefined`, meaning "use whatever the watcher reports". Existing explicit `watch: [...]` configurations keep working unchanged.
- Regenerations now run against a persistent Laravel boot via NDJSON. Typical edit latency drops from ~300-500ms to ~20-60ms.

## Alternative install (from vendor)

If you prefer not to add an npm dependency, the Composer package bundles the built plugin in its `dist/` directory:

```ts
import typefinder from '../../vendor/pentacore/laravel-typefinder/dist/index.mjs';
```

Adjust the relative path to match your project structure. This approach means the plugin version is tied to your Composer lock file — which is often desirable.

## License

MIT — see [LICENSE](../../LICENSE).
