# Typefinder reference

Detailed reference material for agents that need more than the surface-level skill prompt.

## Command

```
php artisan typefinder:generate [--debug] [--json] [--check] [--strict]
```

| Flag | Purpose |
|---|---|
| `--json` | Emit a single structured JSON object to stdout. Suppresses human output. |
| `--debug` | Emit `[typefinder]`-prefixed diagnostic lines, one per extracted class. |
| `--check` | Dry-run: generate into a temp directory, diff against on-disk output, exit non-zero if they differ. Writes no files. Only meaningful when the output is committed (`gitignore_generated` => false); with ignored output, generate in CI instead. |
| `--strict` | Exit 1 if the run produced any warning (files are still written). Combine with `--check` or add it to the regular generate step in CI. |

## Emitted categories

| Category | Default | Path | Source |
|---|---|---|---|
| Models | on | `{output_path}/models/{Name}.d.ts` (+ `{Name}Create` / `{Name}Update`) | `app/Models/*` |
| Enums | on | `{output_path}/enums/{Name}.d.ts` (or `.ts` when `emit_values` on) | `app/Enums/*` (backed) |
| Form Requests | on | `{output_path}/requests/{Name}.d.ts` | `app/Http/Requests/*` |
| Pivots | on | `{output_path}/models/{Name}Pivot.d.ts` | derived from `belongsToMany` / `morphToMany` |
| Resources | on | `{output_path}/resources/{Name}.d.ts` | `app/Http/Resources/*` |
| Pages | off | `{output_path}/pages.d.ts` (single file) | controller actions tagged `#[TypefinderPage]` |
| Broadcasting | off | `{output_path}/broadcasting.d.ts` (single file) | classes implementing `ShouldBroadcast` |
| Helpers | always | `{output_path}/helpers.d.ts` | built-in generic response wrappers |
| Top-level barrel | always | `{output_path}/index.d.ts` | re-exports every active category |

## Cast type map

| PHP cast | TS type |
|---|---|
| `'string'` / `'alpha'` / `'email'` / `'url'` / `'uuid'` / `'ulid'` / … | `string` |
| `'integer'` / `'int'` / `'float'` / `'decimal:2'` / `'numeric'` | `number` |
| `'boolean'` / `'bool'` / `'accepted'` / `'declined'` | `boolean` |
| `'datetime'` / `'date'` / `'timestamp'` / `'immutable_*'` / `'custom_datetime'` (with or without a `:format`) | `string` |
| `'array'` / `'collection'` / `'encrypted:array'` / `'encrypted:collection'` | `unknown[]` |
| `'object'` / `'encrypted:object'` | `Record<string, unknown>` |
| `'json'` / `'encrypted:json'` | `unknown` |
| `'hashed'` | `string` |
| `AsArrayObject` / `AsEncryptedArrayObject` / `AsFluent` | `Record<string, unknown>` |
| `AsCollection` / `AsEncryptedCollection` (incl. `::using()` / `::of()`) | `unknown[]` |
| `AsStringable` / `AsUri` / `AsHtmlString` | `string` |
| `AsEnumCollection:{Enum}` / `AsEnumArrayObject:{Enum}` | inferred enum type, e.g. `PostStatus[]` |
| Parameterised cast `Name:args` with no exact mapping | resolved as `Name` |
| Unknown cast (no `#[TypefinderCast]` attribute, no `Typefinder::registerCast()` entry, no `typefinder.casts.type_map` entry, not a backed enum) | `unknown` (warning emitted) |

## Type resolution precedence — models, resources, requests

When multiple sources could provide a type for a model attribute, priority (highest first):

1. `#[TypefinderOverrides([...])]` on the model class.
2. Cast resolution (see below).
3. `BackedEnum` cast auto-detection.
4. Relationship introspection.
5. DB schema column type.

## Type resolution precedence — resources

For a `JsonResource` subclass, the first match wins:

1. `#[TypefinderIgnore]` → no file.
2. `#[TypefinderResource(shape: [...])]`.
3. `#[TypefinderResource(model: X::class, omit: [...], extend: [...])]`.
4. PHPDoc `@return array{…}` on `toArray()` (`@phpstan-return` > `@psalm-return` > `@return`). Applies even with `infer_from_model_name` off; never to abstract classes. `array<string, mixed>` (the `make:resource` stub), plain `array` and `list<…>` are not shapes and fall through. Classes inside the shape resolve only to generated models, enums and resources; `Carbon`/`DateTimeInterface` become `string`, collections become arrays, `stdClass` becomes `Record<string, unknown>`; any other class becomes `unknown` with a warning. Without a `@return` shape on the resource's own `toArray()`, a body whose every `return` is built only from documented helpers (`$this->m()`, `self::m()`, `static::m()`; an undocumented helper is composed from its own body), a documented or composed `parent::toArray()` (not `JsonResource`'s own) and literal keys is composed into a shape: with spreads, `array_merge()` and literal keys a later key wins; a key repeated in a return that uses `+`, `merge()`, `mergeWhen()` or `mergeUnless()`, a later optional/conditional key over an earlier one, an open helper shape that is not the first part of its return (or sits beside `+` / a merge call), a merge call inside a helper or parent `toArray()` without a `@return` shape, `+` together with a merge call, or a merge call that may add nothing (an empty array, a helper whose keys are all optional) followed by more keys means no composition. Keys inside `mergeWhen()` / `mergeUnless()`, `when*()` / `unless()` / `transform()` values without a default and keys missing from some return are optional. Literal keys are `unknown` with one warning per resource (`… composes a shape but "k" has no documented type — add @return array{…} to toArray()`). At least one delegated part is needed (a purely literal body falls through), at most 3 delegations deep; any untypeable part (variable, foreign call, computed key) means no composition. No composition always falls back as before (name convention or skipped), silently. Helper docs resolve names in the helper's declaring file.
5. Name convention `{Model}Resource` → `{Model}` (only when `infer_from_model_name` is true).
6. Otherwise skipped with a warning.

## Drift checks

Generation warns (key names only; types are not compared) when a resource's declared shape (attribute or PHPDoc) and the literal keys of its own-file `toArray()` disagree, and, with Inertia enabled, when a `#[TypefinderPage]`'s props and the props the same action passes to `Inertia::render()` / `inertia()` (with chained `->with()`) disagree. A resource typed as a model (name convention or `#[TypefinderResource(model:)]`) is compared with the model type (columns required, relations optional, less `omit`, plus `extend`; hidden columns are not in it) and gets one aggregated warning: `{R}::toArray() does not match the {M} model type it is typed as (by name): returns undeclared "a"; never returns "b"; … — adjust it with #[TypefinderResource(model: …, omit: …, extend: …)], declare its shape with #[TypefinderResource(shape: …)] or a PHPDoc @return array{…} on toArray(), or set typefinder.resources.infer_from_model_name to false` (attribute: `adjust omit/extend in #[TypefinderResource] or declare a shape instead`). Default and `parent::toArray()` bodies are not checked. `--only` / watch runs need the model in the extraction cache (run a full generate first); with a cold cache this check is skipped silently. Repeated `#[TypefinderPage]` attributes for one component on one action are merged first. When the code is not fully literal only "undeclared key" warnings are emitted; only renders the action makes directly are read (not those in a helper method or base controller): if every return is such a call there are no page warnings, and if the action also returns something other than a direct render of the page (helper call, redirect, another page), only undeclared-prop warnings remain. Shared props (`errors`, keys returned by `share()` in `app/Http/Middleware` classes extending `Inertia\Middleware`, and every prop of an action that calls `Inertia::share()`) are never reported as not passed. In watch mode, attribute edits on an already-loaded class are seen stale until the dev server restarts. `--strict` fails the run on them. Fix the declaration (or add the key to `optional` / mark it `?`), not the generated file.

## Type resolution precedence — casts

Inside the cast resolver, priority (highest first):

1. `Typefinder::registerCast(...)` runtime registrations.
2. `typefinder.casts.type_map` config array.
3. `#[TypefinderCast('T')]` attribute on the cast class.
4. Built-in name map (`'datetime'`, `'boolean'`, …) and class map (`AsCollection::class`, …).
5. `BackedEnum` subclass detection.
6. Fallback to `'unknown'` with a warning.

## JSON output shape

```json
{
  "success": true,
  "duration_ms": 47,
  "output_path": "/abs/path/to/resources/js/typefinder",
  "counts": { "models": 3, "enums": 2, "requests": 2, "resources": 1 },
  "files": [
    { "path": "models/Post.d.ts", "written": true },
    { "path": "models/User.d.ts", "written": false }
  ],
  "warnings": [],
  "errors": []
}
```

## Generated file layout

@verbatim
```
{output_path}/              # default: resources/js/typefinder/
├── helpers.d.ts            # always emitted — generic response wrappers
├── models/                 # Eloquent models + derived pivots
│   ├── {ModelName}.d.ts    # includes the Read, Create, and Update types
│   ├── {PivotName}Pivot.d.ts
│   └── index.d.ts          # barrel — re-exports every model, write shape + pivot
├── enums/                  # `.ts` when enums.emit_values is on
│   ├── {EnumName}.d.ts
│   └── index.d.ts
├── requests/
│   ├── {RequestName}.d.ts
│   └── index.d.ts
├── resources/
│   ├── {ResourceName}.d.ts
│   └── index.d.ts
├── pages.d.ts              # opt-in — Inertia PageProps map
├── broadcasting.d.ts       # opt-in — Echo channel + event types
└── index.d.ts              # top-level barrel
```
@endverbatim

Stale files (on disk but no longer produced) are automatically pruned from managed directories on each run.

## Config keys

All live under `config/typefinder.php`:

```php
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
        'emit_values' => false,
    ],

    'requests' => [
        'enabled' => true,
        'paths' => [app_path('Http/Requests')],
        'extract_nested' => false,
    ],

    'resources' => [
        'enabled' => true,
        'paths' => [app_path('Http/Resources')],
        'infer_from_model_name' => true, // false: undeclared resources warn instead of getting the model's type
    ],

    'inertia' => [
        'enabled' => false,
        'paths' => [app_path('Http/Controllers')],
    ],

    'broadcasting' => [
        'enabled' => false,
        'paths' => [app_path('Events')],
    ],

    'casts' => [
        'type_map' => [],
    ],
];
```
