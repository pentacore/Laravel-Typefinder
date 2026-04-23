# laravel-typefinder

[![Packagist Version](https://img.shields.io/packagist/v/pentacore/laravel-typefinder?logo=packagist&logoColor=white)](https://packagist.org/packages/pentacore/laravel-typefinder)
[![Packagist Downloads](https://img.shields.io/packagist/dt/pentacore/laravel-typefinder?logo=packagist&logoColor=white)](https://packagist.org/packages/pentacore/laravel-typefinder/stats)
[![PHP Version](https://img.shields.io/packagist/php-v/pentacore/laravel-typefinder?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/pentacore/Laravel-Typefinder/actions/workflows/tests.yml)
[![codecov](https://codecov.io/github/pentacore/Laravel-Typefinder/graph/badge.svg?token=QZGUJ8XF9D&component=Laravel%20Typefinder)](https://codecov.io/github/pentacore/Laravel-Typefinder)
[![License](https://img.shields.io/github/license/pentacore/Laravel-Typefinder)](../../LICENSE)

Auto-generate TypeScript type definitions from your Laravel application's Models, Enums, Form Requests, API Resources, Inertia pages, and broadcast events.

Laravel Typefinder introspects your database schema, `$casts` declarations, validation rules, Eloquent relationships, controller attributes, and broadcast events to emit accurate `.d.ts` files into your frontend source tree. Types stay in sync without any manual maintenance — run the artisan command or let the Vite plugin do it on every HMR change.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Publishing config](#publishing-config)
- [Usage](#usage)
- [What gets generated](#what-gets-generated)
  - [Models](#models)
  - [Enums](#enums)
  - [Form Requests](#form-requests)
  - [Pivots](#pivots)
  - [Resources](#resources-default-on)
  - [Pages](#pages-opt-in-typefinderinertiaenabled)
  - [Broadcasting](#broadcasting-opt-in-typefinderbroadcastingenabled)
  - [Helpers](#helpers-always-emitted)
- [Attribute reference](#attribute-reference)
- [Custom casts](#custom-casts)
- [Configuration](#configuration)
- [JSON output shape](#json-output-shape---json)
- [Publishing the Claude Code skill](#publishing-the-claude-code-skill)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

> `Rule::anyOf()` support in Form Request generation requires Laravel 13+. The package works on older Laravel versions — that rule is simply not specially handled.

## Installation

```bash
composer require pentacore/laravel-typefinder
```

The service provider is auto-discovered.

## Publishing config

```bash
php artisan vendor:publish --tag=typefinder-config
```

This copies `config/typefinder.php` into your application's `config/` directory.

## Usage

### Generating types

```bash
php artisan typefinder:generate
```

Scans configured directories, resolves types, and writes `.d.ts` files to `output_path`. Only files whose content has changed are rewritten; stale generated files are pruned automatically. The `output_path` is added to your project `.gitignore` on first run.

### Flags

| Flag | Description |
|---|---|
| `--check` | Dry-run: generate into a temp directory, compare against the on-disk output, exit non-zero if they differ. Useful as a CI gate. |
| `--debug` | Print line-oriented diagnostic output prefixed with `[typefinder]`. Includes per-class parsing lines so you can pinpoint a failing class. |
| `--json` | Output a single JSON object to stdout (machine-readable, used by the Vite plugin). |
| `--only=<path>` | Re-extract only the given absolute paths instead of the full tree. Repeatable (`--only=/a/Foo.php --only=/a/Bar.php`). Used by the Vite plugin's watch loop for incremental regeneration; rarely useful by hand. |

### Watching for changes

```bash
php artisan typefinder:watch
```

Starts a long-lived generator process that reads incremental regeneration requests from stdin (NDJSON) and emits status lines on stdout. Not intended for direct interactive use — the [Vite plugin](../vite-plugin-laravel-typefinder/) spawns and drives it during `vite dev` for 20–60ms per-change latency. Exits on `SIGINT` / `SIGTERM`.

## What gets generated

Every run produces a single tree under `output_path` (default `resources/js/typefinder/`). The categories below are each gated by config — most are default-on, the ones that depend on optional packages are opt-in.

```
resources/js/typefinder/
├── index.d.ts                  top-level barrel — re-exports every category
├── helpers.d.ts                generic response wrappers (always emitted)
├── models/                     Eloquent models + derived pivots
│   ├── User.d.ts               contains User, UserCreate, UserUpdate
│   ├── UserRolePivot.d.ts      pivots live alongside their models
│   └── index.d.ts
├── enums/                      backed PHP enums (`.ts` when emit_values is on)
│   ├── PostStatus.d.ts
│   └── index.d.ts
├── requests/                   FormRequest classes
│   ├── StorePostRequest.d.ts
│   └── index.d.ts
├── resources/                  JsonResource subclasses
│   ├── UserResource.d.ts
│   └── index.d.ts
├── pages.d.ts                  Inertia PageProps map (opt-in)
└── broadcasting.d.ts           Echo channel + event types (opt-in)
```

### Models

Types represent the default JSON-serializable shape of an Eloquent model — the shape you get when you return a model directly from a controller or `toArray()`. Relationships are optional (present only when eagerly loaded). `$hidden`/`$visible` are respected.

Each model file also contains `{Model}Create` and `{Model}Update` companion types derived from the same metadata (unless you disable `emit_write_shapes` in config). Create shapes omit server-filled fields (primary key, timestamps); Update shapes make every field optional and drop immutable columns.

```typescript
import type { PostStatus } from '../enums';
import type { User } from './User';

export type Post = {
  id: number;
  title: string;
  status: PostStatus;
  published_at: string | null;
  user?: User | null;
};

export type PostCreate = {
  title: string;
  status: PostStatus;
  published_at?: string | null;
};

export type PostUpdate = {
  title?: string;
  status?: PostStatus;
  published_at?: string | null;
};
```

### Enums

Backed PHP enums become TypeScript string or number literal union types:

```typescript
export type PostStatus = 'draft' | 'published' | 'archived';
export type Priority = 1 | 2 | 3;
```

Set `enums.emit_values: true` to emit `.ts` files with both an `as const` object (for runtime iteration) and the matching union type:

```typescript
export const PostStatus = {
  Draft: 'draft',
  Published: 'published',
  Archived: 'archived',
} as const;

export type PostStatus = typeof PostStatus[keyof typeof PostStatus];
```

Handy for `<select options={Object.values(PostStatus)}>` and similar runtime patterns. Opt-in because it switches the file extension and the barrel's re-export style.

### Form Requests

Validation rules map to TypeScript types. `required` → non-optional; `nullable` → adds `| null`; `sometimes` and conditional `required_*` rules → optional. Fields without `required` are optional.

```typescript
import type { PostStatus } from '../enums';

export type StorePostRequest = {
  title: string;
  body: string;
  status: PostStatus;
  tags?: string[];
  metadata?: { key?: string };
};
```

With `extract_nested: true`, nested keys become named types (`StorePostRequestMetadata`).

### Pivots

Pivot types are derived from `belongsToMany` / `morphToMany` declarations. They're written into the same `models/` directory as their parent types — no separate `pivots/` subdirectory — so a relationship field like `roles?: (Role & { pivot: UserRolePivot })[];` resolves with a simple sibling import:

```typescript
export type UserRolePivot = {
  user_id: number;
  role_id: number;
  assigned_at: string | null;
};
```

### Resources (default-on)

Classes extending `JsonResource`. Three declaration tiers are tried in order:

1. **Explicit shape** via `#[TypefinderResource(shape: [...])]`.
2. **Model extension** via `#[TypefinderResource(model: User::class, omit: [...], extend: [...])]` → `Omit<User, ...> & { ... }`.
3. **Name convention** — `UserResource` → `User` automatically when a matching model was discovered.

Resources that match none of the above are skipped with a warning.

### Pages (opt-in, `typefinder.inertia.enabled`)

Consolidated `pages.d.ts` with a `PageProps` map keyed by Inertia component name. Controllers declare their pages via `#[TypefinderPage(component: ..., props: [...])]` on action methods. Collisions (same component from two methods) fail the run.

### Broadcasting (opt-in, `typefinder.broadcasting.enabled`)

Consolidated `broadcasting.d.ts` with four maps: `BroadcastPublicChannels`, `BroadcastPrivateChannels`, `BroadcastPresenceChannels`, and a flat `BroadcastEvents` keyed by broadcast name. Classes implementing `ShouldBroadcast` are discovered automatically; `#[TypefinderBroadcast(payload: [...], channel: ...)]` overrides reflection for the tricky cases.

### Helpers (always emitted)

`helpers.d.ts` ships nine generic response wrappers that work with *any* generated type:

```typescript
// JsonResource / ResourceCollection envelopes
WrappedResource<T>                      { data: T }
WrappedResourceCollection<T>            { data: T[] }

// Paginated JsonResource collections
PaginatedResourceCollection<T>          paginate()       — adds links + full meta
CursorPaginatedResourceCollection<T>    cursorPaginate() — adds meta (next/prev cursor)
SimplePaginatedResourceCollection<T>    simplePaginate() — adds meta (no total)

// Raw model paginator (Model::paginate() without a Resource)
PaginatedModel<T>                       PaginationFields & { data: T[] }
PaginationFields                        current_page, last_page, total, links, …

// Error envelopes
ValidationErrorResponse                 { message: string; errors: Record<string, string[]> }
ErrorResponse                           { message: string }
```

Consumers write `WrappedResource<UserResource>` or `PaginatedResourceCollection<PostResource>` at the fetch site. When you return a raw paginator from `Model::paginate()` without wrapping in a Resource, use `PaginatedModel<User>` instead.

## Attribute reference

All attributes live under `Pentacore\Typefinder\Attributes\`.

| Attribute | Target | Purpose |
|---|---|---|
| `#[TypefinderIgnore]` | class | Skip any model, enum, form request, resource, controller, or event. |
| `#[TypefinderOverrides(['col' => 'T'])]` | model, form request | Override or add fields. Highest priority in type resolution. |
| `#[TypefinderWriteShape(serverFilled, respectMassAssignment, immutableOnUpdate)]` | model | Tune the Create/Update companions. |
| `#[TypefinderResource(shape / model / omit / extend)]` | `JsonResource` subclass | Declare a resource's TS shape or model extension. |
| `#[TypefinderPage(component, props, optional)]` | controller action method | Map the method to an Inertia page component. Repeatable. |
| `#[TypefinderBroadcast(payload, as, channel, channelType)]` | broadcast event class | Override reflection for events with dynamic `broadcastOn()`/`broadcastWith()`. |
| `#[TypefinderCast('T')]` | custom cast class | Declare the TS shape for a cast you own. |

## Custom casts

### Your own casts — `#[TypefinderCast]`

For cast classes you control, tag them with the attribute:

```php
use Pentacore\Typefinder\Attributes\TypefinderCast;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

#[TypefinderCast('{ theme: string; notifications: boolean }')]
class SettingsCast implements CastsAttributes
{
    // ... get() / set() implementations
}
```

### Third-party casts — `Typefinder` facade

For casts you can't modify (Spatie, Cknow, etc.), register them from a service provider:

```php
// AppServiceProvider::boot()
use Pentacore\Typefinder\Facades\Typefinder;

Typefinder::registerCast(
    \Spatie\MediaLibrary\Cast::class,
    'Media[]',
);

// Closure form — useful when the shape depends on runtime config:
Typefinder::registerCast(
    \Cknow\Money\MoneyCast::class,
    fn () => config('app.strict_money')
        ? '{ amount: number; currency: string; formatted: string }'
        : '{ amount: number; currency: string }',
);
```

### Resolution priority

When a cast is encountered, the resolver tries in order:

1. Runtime registry (`Typefinder::registerCast(...)`).
2. Config overrides (`typefinder.casts.type_map`).
3. `#[TypefinderCast]` attribute on the cast class.
4. Built-in name map (`datetime`, `array`, etc.) and class map (`AsCollection::class`, etc.).
5. `BackedEnum` detection → emits a reference to the generated enum type.
6. Fall back to `unknown` (emits a warning in `--json` mode).

## Configuration

```php
// config/typefinder.php
return [
    'output_path' => resource_path('js/typefinder'),
    'gitignore_generated' => true,

    'models' => [
        'enabled' => true,
        'paths' => [app_path('Models')],
        'include_relationships' => true,
        'emit_write_shapes' => true,
        'respect_mass_assignment' => true,
        'immutable_on_update' => ['id', 'created_at', 'updated_at', 'deleted_at'],
    ],

    'enums' => [
        'enabled' => true,
        'paths' => [app_path('Enums')],
        'emit_values' => false,                         // true → `.ts` with `as const` runtime values
    ],

    'requests' => [
        'enabled' => true,
        'paths' => [app_path('Http/Requests')],
        'extract_nested' => false,
    ],

    'resources' => [
        'enabled' => true,                              // default-on; ships with Laravel core
        'paths' => [app_path('Http/Resources')],
    ],

    'inertia' => [
        'enabled' => false,                             // opt-in
        'paths' => [app_path('Http/Controllers')],
    ],

    'broadcasting' => [
        'enabled' => false,                             // opt-in
        'paths' => [app_path('Events')],
    ],

    'casts' => [
        'type_map' => [],
    ],
];
```

## JSON output shape (`--json`)

```json
{
  "success": true,
  "counts": { "models": 3, "enums": 2, "requests": 2, "resources": 1, "pivots": 1 },
  "files": [
    { "path": "models/Post.d.ts", "written": true },
    { "path": "models/User.d.ts", "written": false }
  ],
  "warnings": ["skipped App\\Http\\Resources\\Foo: no shape, model, or name-match"],
  "errors": []
}
```

## Publishing the Claude Code skill

Optional — if you use [Claude Code](https://claude.com/claude-code), publish the bundled skill so Claude knows how to invoke Typefinder itself:

```bash
php artisan vendor:publish --tag=typefinder-skill
```

This copies a skill definition into `.claude/skills/` that teaches Claude Code to run `php artisan typefinder:generate --json`, parse the output, and act on warnings.

## Testing

```bash
vendor/bin/phpunit
```

Tests use Orchestra Testbench with a real SQLite database and the `workbench/` Laravel application as fixtures.

## License

MIT — see [LICENSE](../../LICENSE).
