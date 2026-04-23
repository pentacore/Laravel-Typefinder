# Laravel Typefinder

[![Tests](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml)
[![codecov](https://codecov.io/github/pentacore/Laravel-Typefinder/graph/badge.svg?token=QZGUJ8XF9D)](https://codecov.io/github/pentacore/Laravel-Typefinder)
[![License](https://img.shields.io/github/license/pentacore/Laravel-Typefinder)](LICENSE)
[![semantic-release](https://img.shields.io/badge/semantic--release-conventional-e10079?logo=semantic-release&logoColor=white)](https://github.com/semantic-release/semantic-release)

[![Packagist Version](https://img.shields.io/packagist/v/pentacore/laravel-typefinder?label=composer&logo=packagist&logoColor=white)](https://packagist.org/packages/pentacore/laravel-typefinder)
[![Packagist Downloads](https://img.shields.io/packagist/dt/pentacore/laravel-typefinder?label=downloads&logo=packagist&logoColor=white)](https://packagist.org/packages/pentacore/laravel-typefinder/stats)
[![NPM Version](https://img.shields.io/npm/v/%40pentacore%2Fvite-plugin-laravel-typefinder?label=npm&logo=npm&logoColor=white)](https://www.npmjs.com/package/@pentacore/vite-plugin-laravel-typefinder)
[![NPM Downloads](https://img.shields.io/npm/dm/%40pentacore%2Fvite-plugin-laravel-typefinder?label=downloads&logo=npm&logoColor=white)](https://www.npmjs.com/package/@pentacore/vite-plugin-laravel-typefinder)

Laravel Typefinder auto-generates TypeScript type definitions (`.d.ts` files) from your Laravel application. It introspects Eloquent models, backed enums, Form Requests, API Resources, Inertia controllers, broadcast events, and pivot tables to produce accurate, always-fresh types — no manual maintenance required. Ships with opt-in attributes (`#[TypefinderOverrides]`, `#[TypefinderWriteShape]`, `#[TypefinderResource]`, `#[TypefinderPage]`, `#[TypefinderBroadcast]`, `#[TypefinderCast]`, `#[TypefinderIgnore]`) for the cases where static inference needs a nudge, plus a runtime facade for registering types for third-party casts.

## Contents

- [Packages](#packages)
- [At a glance](#at-a-glance)
- [Quick start](#quick-start)
- [Supported matrix](#supported-matrix)
- [Documentation](#documentation)
- [Development](#development)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Packages

| Package | Install |
|---|---|
| [`pentacore/laravel-typefinder`](packages/laravel-typefinder/) | `composer require pentacore/laravel-typefinder` |
| [`@pentacore/vite-plugin-laravel-typefinder`](packages/vite-plugin-laravel-typefinder/) | `npm i -D @pentacore/vite-plugin-laravel-typefinder` |

## At a glance

You write this:

```php
// app/Models/Post.php
class Post extends Model
{
    protected $casts = [
        'status' => PostStatus::class,
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Typefinder emits this:

```typescript
// resources/js/typefinder/models/Post.d.ts
import type { PostStatus } from '../enums';
import type { User } from './User';

export type Post = {
  id: number;
  title: string;
  status: PostStatus;
  published_at: string | null;
  author?: User | null;
};

export type PostCreate = { title: string; status: PostStatus; published_at?: string | null };
export type PostUpdate = { title?: string; status?: PostStatus; published_at?: string | null };
```

No decorators, no manual schemas — it reads your migrations, `$casts`, and relationships directly.

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

## Supported matrix

Every cell below is exercised in CI on every push and PR.

|          | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|----------|:-------:|:-------:|:-------:|
| Laravel 11 | ✅ | ✅ | — |
| Laravel 12 | ✅ | ✅ | ✅ |
| Laravel 13 | ✅ | ✅ | ✅ |

## Documentation

Full documentation for each package:

- [packages/laravel-typefinder/README.md](packages/laravel-typefinder/README.md) — configuration, every generated category (models / enums / requests / resources / pivots / pages / broadcasting / helpers), the full attribute reference, the third-party cast registry, and the artisan commands (`typefinder:generate`, `typefinder:watch`) with flags (`--check`, `--json`, `--debug`, `--only=`).
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

## Contributing

Bug reports, feature proposals, and PRs are welcome. Start with [CONTRIBUTING.md](CONTRIBUTING.md) — it covers the commit-message convention (Conventional Commits), the pre-commit toolchain (`pint`, `rector`, `npm run lint`), and the CI matrix your changes need to pass.

## Security

If you've found a security issue, please follow the disclosure process in [SECURITY.md](SECURITY.md) rather than opening a public issue.

## License

MIT — see [LICENSE](LICENSE).
