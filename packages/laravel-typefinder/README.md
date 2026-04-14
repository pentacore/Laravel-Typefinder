# laravel-typefinder

Auto-generate TypeScript type definitions from your Laravel application's Models, Enums, Form Requests, and Casts.

Laravel Typefinder introspects your database schema, `$casts` declarations, validation rules, and Eloquent relationships to emit accurate `.d.ts` files into your frontend source tree. Types stay in sync without any manual maintenance — run the artisan command or let the Vite plugin do it on every HMR change.

## Requirements

- PHP 8.2+
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

## Configuration

```php
// config/typefinder.php
return [
    /*
     | The directory where generated .d.ts files will be written.
     */
    'output_path' => resource_path('js/types'),

    'models' => [
        'enabled' => true,

        // Directories to scan for Eloquent model classes.
        'paths' => [app_path('Models')],

        // Whether to introspect relationship methods and include them in types.
        'include_relationships' => true,
    ],

    'enums' => [
        'enabled' => true,

        // Directories to scan for backed PHP enum classes.
        'paths' => [app_path('Enums')],
    ],

    'requests' => [
        'enabled' => true,

        // Directories to scan for FormRequest classes.
        'paths' => [app_path('Http/Requests')],

        // When true, nested validation keys (e.g. "address.street") are extracted
        // into separate named types instead of inline object literals.
        'extract_nested' => false,
    ],

    'casts' => [
        // Override or extend the built-in cast → TypeScript type mappings.
        // Example: 'datetime' => 'Date',
        'type_map' => [],
    ],
];
```

### Key options

| Key | Default | Description |
|---|---|---|
| `output_path` | `resource_path('js/types')` | Root directory for all generated `.d.ts` files |
| `models.enabled` | `true` | Enable/disable model type generation |
| `models.paths` | `[app_path('Models')]` | Directories to scan for Eloquent models |
| `models.include_relationships` | `true` | Introspect relationship methods and include optional relationship fields |
| `enums.enabled` | `true` | Enable/disable enum type generation |
| `enums.paths` | `[app_path('Enums')]` | Directories to scan for backed PHP enums |
| `requests.enabled` | `true` | Enable/disable form request type generation |
| `requests.paths` | `[app_path('Http/Requests')]` | Directories to scan for FormRequest classes |
| `requests.extract_nested` | `false` | Extract nested object types into separate named types |
| `casts.type_map` | `[]` | Extend or override the built-in cast→TS type map |

## Usage

### Generating types

```bash
php artisan typefinder:generate
```

This scans all configured directories, resolves types, and writes `.d.ts` files to `output_path`. Only files whose content has changed are rewritten; stale generated files are pruned automatically.

### Flags

| Flag | Description |
|---|---|
| `--debug` | Print line-oriented diagnostic output prefixed with `[typefinder]` |
| `--json` | Output a single JSON object to stdout (machine-readable, used by the Claude Code skill and Vite plugin) |

### JSON output shape

```json
{
    "success": true,
    "counts": {
        "models": 3,
        "enums": 2,
        "requests": 2,
        "pivots": 1
    },
    "files": [
        { "path": "resources/js/typefinder/models/Post.d.ts", "written": true },
        { "path": "resources/js/typefinder/models/User.d.ts", "written": false }
    ],
    "warnings": [
        "Unknown cast type 'App\\Casts\\FooCast' — defaulting to unknown"
    ],
    "errors": []
}
```

- `success` — `false` if a fatal error occurred; read `errors` for details
- `counts` — number of types generated per category
- `files[].written` — `true` if the file was actually rewritten; `false` means the type was unchanged
- `warnings` — non-fatal notices (e.g. unknown custom cast)
- `errors` — fatal errors that prevented generation

## What gets generated

### Output structure

```
resources/js/typefinder/
├── models/
│   ├── Post.d.ts
│   ├── User.d.ts
│   └── index.d.ts
├── enums/
│   ├── PostStatus.d.ts
│   └── index.d.ts
├── requests/
│   ├── StorePostRequest.d.ts
│   └── index.d.ts
├── pivots/
│   ├── UserRolePivot.d.ts
│   └── index.d.ts
└── index.d.ts
```

### Models

Types represent the default JSON-serializable shape of an Eloquent model before API Resource transformation — the shape you get when you return a model directly from a controller or `toArray()`.

```typescript
import { PostStatus } from '../enums';
import { Comment } from './Comment';
import { User } from './User';
import { Tag } from './Tag';
import { TaggablePivot } from '../pivots';

export type Post = {
  id: number;
  title: string;
  body: string;
  user_id: number;
  status: PostStatus;
  published_at: string | null;
  metadata: Record<string, unknown>;
  created_at: string;
  updated_at: string;

  // Relationships (optional — only present when eagerly loaded)
  user?: User | null;
  comments?: Comment[];
  tags?: (Tag & { pivot: TaggablePivot })[];
};
```

Attributes in `$hidden` are excluded. When `$visible` is non-empty, only those attributes are included.

### Enums

Backed PHP enums become TypeScript string or number literal union types:

```typescript
// string-backed enum
export type PostStatus = 'draft' | 'published' | 'archived';

// integer-backed enum
export type Priority = 1 | 2 | 3;
```

### Form Requests

Validation rules are mapped to TypeScript types:

```typescript
import { PostStatus } from '../enums';

export type StorePostRequest = {
  title: string;
  body: string;
  status: PostStatus;
  tags?: string[];
  metadata?: {
    key?: string;
  };
};
```

- `required` → non-optional field
- `nullable` → adds `| null`
- `sometimes` → optional field (`?`)
- Conditional rules (`required_if`, `required_unless`, etc.) → optional field
- Fields without `required` or `sometimes` → optional

With `extract_nested: true`, nested keys are extracted into named types:

```typescript
export type StoreUserRequestAddress = {
  street: string;
  city: string;
  zip?: string | null;
};

export type StoreUserRequest = {
  address: StoreUserRequestAddress;
};
```

### Pivots

Pivot types are generated for `belongsToMany` and `morphToMany` relationships:

```typescript
export type UserRolePivot = {
  user_id: number;
  role_id: number;
  assigned_at: string | null;
};
```

## Model attribute: `#[TypefinderOverrides]`

Apply at class level to manually override inferred types or add virtual fields (e.g. accessor-only attributes):

```php
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides([
    // Override an inferred type
    'metadata' => 'Record<string, string>',
    // Add a virtual/accessor-only field
    'full_title' => 'string',
])]
class Post extends Model {}
```

`#[TypefinderOverrides]` has the highest priority in the type resolution chain — it always wins over cast, column, or relationship inference.

## Model attribute: `#[TypefinderWriteShape]`

Tune the generated `ModelCreate` / `ModelUpdate` companion types for a specific model:

```php
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;

#[TypefinderWriteShape(
    serverFilled: ['reference'],           // extra fields omitted from Create
    respectMassAssignment: false,          // ignore $fillable/$guarded for this model
    immutableOnUpdate: ['customer_id'],    // extra fields excluded from Update
)]
class Invoice extends Model {}
```

## Skip a class: `#[TypefinderIgnore]`

Class-level marker. Works on models, enums, form requests, and controllers. Any class tagged with this attribute is skipped by the generator:

```php
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
class LegacyModel extends Model {}
```

## Custom casts: HasTypeDefinition

Custom cast classes can tell Typefinder their TypeScript shape by implementing `HasTypeDefinition`:

```php
use Pentacore\Typefinder\Contracts\HasTypeDefinition;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class SettingsCast implements CastsAttributes, HasTypeDefinition
{
    public static function typeDefinition(): string
    {
        return '{ theme: string; notifications: boolean }';
    }

    // ... get() / set() implementations
}
```

Without this interface, unknown custom casts fall back to `unknown` (a warning is emitted in JSON output).

## Publishing the Claude Code skill

If you use Claude Code, you can publish the `typefinder` skill to `.claude/skills/`:

```bash
php artisan vendor:publish --tag=typefinder-skill
```

The skill teaches Claude Code to run `php artisan typefinder:generate --json`, parse the output, and act on warnings.

## Publishing everything

```bash
php artisan vendor:publish --tag=typefinder-all
```

This publishes the config, skill, and any other publishable assets.

## Testing

```bash
vendor/bin/phpunit
```

Tests use Orchestra Testbench with a real SQLite database and the `workbench/` Laravel application as fixtures.

## License

MIT — see [LICENSE](../../LICENSE).
