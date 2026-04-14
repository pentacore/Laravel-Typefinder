# Laravel Typefinder

Laravel Typefinder auto-generates TypeScript type definitions (`.d.ts` files) from your Laravel application's Models, Enums, Form Requests, and Casts. It introspects your database schema, cast declarations, validation rules, and relationships to produce accurate, always-fresh types — no manual maintenance required.

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

Types are written to `resources/js/types/` by default. The Vite plugin re-runs generation automatically on HMR file changes.

## Documentation

Full documentation for each package:

- [packages/laravel-typefinder/README.md](packages/laravel-typefinder/README.md) — configuration, all features, CLI flags, `HasTypeOverrides`, `HasTypeDefinition`
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
