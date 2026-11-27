# @pentacore/vite-plugin-laravel-typefinder

[![NPM Version](https://img.shields.io/npm/v/%40pentacore%2Fvite-plugin-laravel-typefinder?logo=npm&logoColor=white)](https://www.npmjs.com/package/@pentacore/vite-plugin-laravel-typefinder)
[![NPM Downloads](https://img.shields.io/npm/dm/%40pentacore%2Fvite-plugin-laravel-typefinder?logo=npm&logoColor=white)](https://www.npmjs.com/package/@pentacore/vite-plugin-laravel-typefinder)
[![Vite](https://img.shields.io/badge/Vite-6%2B-646CFF?logo=vite&logoColor=white)](https://vitejs.dev/)
[![Types](https://img.shields.io/npm/types/%40pentacore%2Fvite-plugin-laravel-typefinder?logo=typescript&logoColor=white)](https://www.npmjs.com/package/@pentacore/vite-plugin-laravel-typefinder)
[![Tests](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml)
[![codecov](https://codecov.io/github/pentacore/Laravel-Typefinder/graph/badge.svg?token=QZGUJ8XF9D&component=Vite%20Plugin)](https://codecov.io/github/pentacore/Laravel-Typefinder)
[![License](https://img.shields.io/github/license/pentacore/Laravel-Typefinder)](../../LICENSE)

A Vite plugin that keeps your TypeScript type definitions in sync with your Laravel codebase. In development, it spawns a persistent `typefinder:watch` process that regenerates only the `.d.ts` files affected by each edit — typical latency is 20-60ms per change. In production builds, it runs a one-shot `typefinder:generate` before bundling.

> **This plugin is a companion to the [`pentacore/laravel-typefinder`](https://packagist.org/packages/pentacore/laravel-typefinder) Composer package — you must install that first.**

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [Behavior](#behavior)
- [License](#license)

## Requirements

- The Composer package `pentacore/laravel-typefinder` installed and auto-discovered in your Laravel app.
- Vite 6+
- Node 18+
- ESM-only — the package exposes only `dist/index.js`. Consumers must `import` (not `require`) it. Any modern Vite/Laravel app already meets this.

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
    // Set to `false` to skip generation entirely in build mode
    // (the TYPEFINDER_SKIP_BUILD environment variable does the same).
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

    // How long to wait for one regeneration before reporting it failed
    // (e.g. a model accessor that blocks on a network call). 0 disables it.
    regenTimeoutMs: 120_000,

    // Maps a path prefix as the watcher sees it to the same directory on
    // the host Vite runs on. Needed when PHP runs in a container (Sail,
    // Docker): see "Sail and Docker" below.
    pathMap: undefined,

    // Directories whose files change the database schema. A change there
    // triggers a full regeneration. Defaults to `database/migrations` under
    // the Vite root; `false` turns it off.
    migrations: undefined,
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

### Sail and Docker

When PHP runs in a container, the watcher reports its directories as the container sees them (`/var/www/html/app/Models`), while Vite on the host reports changed files under the host path (`/Users/you/project/app/Models/User.php`). Without help the two never match and no regeneration runs. Tell the plugin where the container's project directory lives on the host with `pathMap`:

```ts
typefinder({
    command: './vendor/bin/sail artisan typefinder:watch',
    buildCommand: './vendor/bin/sail artisan typefinder:generate --json',
    pathMap: { '/var/www/html': process.cwd() },
})
```

The mapping is applied both ways: the watcher's directories are translated to host paths to match the files Vite reports, and every changed file is translated back to the container path before it is sent to the watcher. An explicit `watch` array takes host paths. Use the volume mount of your `docker-compose.yml` (`.:/var/www/html` for Sail) as the key and value. With several mounts, add one entry per mount; the longest matching prefix wins.

Symlinked project directories need no mapping: the plugin resolves symlinks on both sides, as PHP does.

## Behavior

**Development (`vite dev`):** The plugin spawns `typefinder:watch` — a persistent Laravel process that boots once and accepts incremental regeneration requests over NDJSON. When a `.php` file matching the watched paths is created, changed, deleted or renamed, the plugin sends the changed file paths to the watcher, which re-extracts only those files and rewrites only the affected `.d.ts` outputs. Multiple rapid edits within the debounce window are coalesced into a single request.

A PHP process cannot reload a class it has loaded, so the watcher answers each request in a forked worker that reads the edited classes from the files as they are now. Forking needs PHP's `pcntl` extension. Without it (Windows, some Docker images), or once a class the application loads while booting (a service provider, for example) has been edited, each request starts a new PHP process instead: the same output, but a Laravel boot slower per change. The watcher says so once on stderr; in the second case, restarting the dev server makes it fast again.

The watched paths are read from the watcher's startup handshake, which mirrors your `config/typefinder.php`. If you enable Inertia pages or broadcasting in the config, the plugin automatically watches those directories too — no manual `watch` array needed.

Creating, editing or deleting a migration triggers a full regeneration. The schema itself changes only when the migration runs, and the watcher notices that on the next regeneration: after `php artisan migrate`, save any watched file (or send the `typefinder:full-regen` event) to pick up the new columns.

**Vue:** Vue's SFC compiler caches the types it resolves for `defineProps<PageProps['Users/Show']>()`, and the generated files are not in Vite's module graph, so nothing would tell it they changed: new props would compile to no props until the dev server restarts. When the project has `vue` installed, the plugin clears that cache for every file a regeneration changed, invalidates the `.vue` modules already compiled and reloads the page. Without Vue this does nothing.

Warnings and per-file failures of every regeneration, the initial one included, are written to stderr. A regeneration that gets no answer within `regenTimeoutMs` is reported as failed and the next one is sent.

**Production (`vite build`):** No watcher. The plugin runs `typefinder:generate --json` once in `buildStart`, prints its warnings, and fails the build on non-zero exit with the generator's error. Set `buildCommand: false`, or the `TYPEFINDER_SKIP_BUILD` environment variable, to skip generation entirely.

### Builds without a database (Docker, CI)

Generating reads the database schema, so a build without a database (a Docker image build, a frontend-only CI job) cannot run it. Commit the generated types and skip build-time generation there: set `TYPEFINDER_SKIP_BUILD` to any value except empty, `0` or `false`, and the plugin uses the committed files as they are.

```dockerfile
RUN npm ci && TYPEFINDER_SKIP_BUILD=1 npm run build
```

Keep a check that the committed types are current where the database is available, e.g. `php artisan typefinder:generate --check` in the test job.

**Shutdown:** The watcher is killed on `closeBundle` and on process exit. A PHP fatal error or out-of-memory while regenerating ends only the worker of that request: the plugin logs the error and the watcher keeps running. If the watcher itself dies mid-session, the plugin logs the error and stops regenerating — restart the dev server to recover.

### Migration from v4.0

- `command` default changed from `typefinder:generate` to `typefinder:watch`. If you explicitly set `command`, update it — dev mode requires the long-lived watcher.
- `watch` default changed from a hardcoded path list to `undefined`, meaning "use whatever the watcher reports". Existing explicit `watch: [...]` configurations keep working unchanged.
- Regenerations now run against a persistent Laravel boot via NDJSON. Typical edit latency drops from ~300-500ms to ~20-60ms.

## License

MIT — see [LICENSE](../../LICENSE).
