# @pentacore/vite-plugin-laravel-typefinder

A Vite plugin that automatically runs `php artisan typefinder:generate` on build start and re-runs it (debounced) whenever watched PHP files change via HMR.

## Requirements

- Vite 6+
- Node 18+

## Installation

```bash
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
        typefinder({
            // All options are optional — defaults shown below
            command: 'php artisan typefinder:generate',
            watch: ['app/Models/**/*.php', 'app/Enums/**/*.php', 'app/Http/Requests/**/*.php'],
            debounceMs: 100,
        }),
    ],
});
```

## Options

| Option | Type | Default | Description |
|---|---|---|---|
| `command` | `string` | `'php artisan typefinder:generate'` | The shell command to run for type generation |
| `watch` | `string[]` | `['app/Models/**/*.php', 'app/Enums/**/*.php', 'app/Http/Requests/**/*.php']` | Glob patterns for files that trigger re-generation on HMR change |
| `debounceMs` | `number` | `100` | Milliseconds to wait after a file change before running the command, to coalesce rapid saves |

## Behavior

**On `buildStart`:** The command runs once synchronously before bundling begins, ensuring your TypeScript types are always fresh before the build processes them.

**On HMR file changes:** When a file matching one of the `watch` patterns changes, the plugin schedules a debounced run. If the command is already running when the timer fires, the new run is queued — only one run is ever active at a time, but at most one follow-up run will be queued to pick up any changes that arrived mid-run. This prevents stale types while avoiding unbounded queuing.

The plugin relies on Typefinder's selective-write behaviour: the PHP command re-extracts the full type graph on every run, but only files whose content has actually changed are rewritten on disk — meaning HMR in your frontend is not needlessly triggered for unchanged types.

## Alternative install (from vendor)

If you prefer not to add an npm dependency, the Composer package bundles the built plugin in its `dist/` directory:

```ts
import typefinder from '../../vendor/pentacore/laravel-typefinder/dist/index.mjs';
```

Adjust the relative path to match your project structure. This approach means the plugin version is tied to your Composer lock file — which is often desirable.

## License

MIT — see [LICENSE](../../LICENSE).
