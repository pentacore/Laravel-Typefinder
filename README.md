# Laravel Typefinder

Laravel Typefinder auto-generates TypeScript type definitions (`.d.ts` files) from your Laravel application. It introspects Eloquent models, backed enums, Form Requests, API Resources, Inertia controllers, broadcast events, and pivot tables to produce accurate, always-fresh types — no manual maintenance required. Ships with opt-in attributes (`#[TypefinderOverrides]`, `#[TypefinderWriteShape]`, `#[TypefinderResource]`, `#[TypefinderPage]`, `#[TypefinderBroadcast]`, `#[TypefinderCast]`, `#[TypefinderIgnore]`) for the cases where static inference needs a nudge, plus a runtime facade for registering types for third-party casts.

## Packages

| Package | Install |
|---|---|
| [`pentacore/laravel-typefinder`](packages/laravel-typefinder/) | `composer require pentacore/laravel-typefinder` |
| [`@pentacore/vite-plugin-laravel-typefinder`](packages/vite-plugin-laravel-typefinder/) | `npm i -D @pentacore/vite-plugin-laravel-typefinder` |

## Quick start

**1. Install the Composer package:**

```bash
composer require pentacore/laravel-typefinder
```

**2. Register the Vite plugin** (`vite.config.js`):

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import typefinder from '@pentacore/vite-plugin-laravel-typefinder';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.js'] }),
        typefinder(),
    ],
});
```

**3. Generate types:**

```bash
php artisan typefinder:generate
```

Types are written to `resources/js/typefinder/` by default. The Vite plugin re-runs generation automatically on HMR file changes.

## Documentation

Full documentation for each package:

- [packages/laravel-typefinder/README.md](packages/laravel-typefinder/README.md) — configuration, every generated category (models / enums / requests / resources / pivots / pages / broadcasting / helpers), the full attribute reference, the third-party cast registry, and CLI flags (`--check`, `--json`, `--debug`).
- [packages/vite-plugin-laravel-typefinder/README.md](packages/vite-plugin-laravel-typefinder/README.md) — plugin options, debounce behaviour, alternative install from vendor

## Development

```bash
# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Run PHP tests
vendor/bin/phpunit

# Check PHP code style
vendor/bin/pint --test

# Build the Vite plugin
npm -w packages/vite-plugin-laravel-typefinder run build

# Lint the Vite plugin
npm -w packages/vite-plugin-laravel-typefinder run lint
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for full contributor guidelines.

## License

MIT — see [LICENSE](LICENSE).
