# Typefinder Reference

This file is a lookup reference for the `typefinder` Claude Code skill. It documents the full type mapping tables, JSON output shape, and relationship cardinality rules used by `php artisan typefinder:generate`.

---

## DB Column Type → TypeScript

The `ColumnTypeResolver` maps database column types (from `getSchemaBuilder()->getColumns()`) to TypeScript types.

| DB Column Type | TypeScript Type |
|---|---|
| `bigint`, `integer`, `smallint`, `tinyint`, `mediumint` | `number` |
| `decimal`, `float`, `double` | `number` |
| `varchar`, `char`, `text`, `mediumtext`, `longtext`, `tinytext` | `string` |
| `boolean` | `boolean` |
| `date`, `datetime`, `timestamp`, `time` | `string` |
| `json`, `jsonb` | `unknown` |
| `blob`, `binary` | `string` |
| `uuid` | `string` |
| `enum` | `string` |
| Unknown column type | `unknown` |

Nullable columns add `| null` to the type. They do not make model attributes optional.

---

## Cast → TypeScript (Built-in Map)

The `CastTypeResolver` maps Laravel cast strings to TypeScript types. All entries are overridable via `casts.type_map` in `config/typefinder.php`.

| Laravel Cast | TypeScript Type |
|---|---|
| `string` | `string` |
| `boolean` | `boolean` |
| `integer`, `int` | `number` |
| `float`, `real`, `double`, `decimal` | `number` |
| `datetime`, `date`, `timestamp`, `immutable_datetime`, `immutable_date` | `string` |
| `array` | `unknown[]` |
| `object` | `Record<string, unknown>` |
| `collection` | `unknown[]` |
| `json` | `unknown` |
| `encrypted` | `string` |
| `encrypted:array` | `unknown[]` |
| `encrypted:collection` | `unknown[]` |
| `encrypted:object` | `Record<string, unknown>` |
| `hashed` | `string` |
| `AsArrayObject` | `Record<string, unknown>` |
| `AsCollection` | `unknown[]` |
| `AsStringable` | `string` |
| `AsEnumCollection` | inferred enum type, e.g. `PostStatus[]` |
| Unknown custom cast without `HasTypeDefinition` | `unknown` (warning emitted) |

### Enum cast auto-detection

When a cast target is a `BackedEnum` subclass, it resolves to the generated enum type automatically:

```php
protected $casts = [
    'status' => PostStatus::class,                             // → PostStatus
    'tags'   => AsEnumCollection::class.':'.PostTag::class,   // → PostTag[]
];
```

---

## Request Rule → TypeScript

The `RequestExtractor` maps Laravel validation rules to TypeScript types.

| Rule | TypeScript Type / Effect |
|---|---|
| `string` | `string` |
| `integer`, `numeric` | `number` |
| `boolean` | `boolean` |
| `array` | inferred from `.*` rules, or `unknown[]` |
| `file`, `image` | `File` |
| `date`, `date_format` | `string` |
| `email`, `url`, `uuid` | `string` |
| `json` | `string` |
| `Rule::enum(SomeEnum::class)` | resolved to the generated enum type |
| `Rule::in([...])` | literal union, e.g. `'a' \| 'b' \| 'c'` |
| `Rule::anyOf([...])` | union of member types (Laravel 13+) |
| `nullable` | adds `\| null` to field type |
| `required` | field is non-optional |
| `sometimes` | field is optional (`?`) |
| `required_if`, `required_unless`, `required_with`, `required_without`, etc. | field is optional (`?`) |
| Nested key `address.street` | property on nested object type |
| Wildcard `tags.*` | array element type |

Fields without `required` or `sometimes` default to optional.

`confirmed` rules auto-generate a matching `{field}_confirmation` field of the same type.

---

## Relationship Cardinality → TypeScript

All relationship fields are optional (`?`) since they are only present when eagerly loaded.

| Laravel Relationship | TypeScript Type |
|---|---|
| `hasOne` / `morphOne` | `RelatedModel \| null` |
| `belongsTo` | `RelatedModel \| null` |
| `hasMany` / `morphMany` | `RelatedModel[]` |
| `belongsToMany` | `(RelatedModel & { pivot: PivotType })[]` |
| `morphToMany` / `morphedByMany` | `(RelatedModel & { pivot: PivotType })[]` |
| `morphTo` | `ModelA \| ModelB \| ... \| null` (union resolved from morphMap + scan) |
| `hasOneThrough` | `RelatedModel \| null` |
| `hasManyThrough` | `RelatedModel[]` |

### Polymorphic (`morphTo`) resolution order

1. `Relation::morphMap()` registered types
2. Scan all models for `morphMany`/`morphOne` methods pointing at this model
3. Fall back to `unknown` if unresolvable

The `morphTo` side uses a generic type parameter for narrowing:

```typescript
export type Comment<T extends Post | Video = Post | Video> = {
  commentable?: T | null;
  commentable_id: number;
  commentable_type: string;
};
```

---

## Type Resolution Precedence (Models)

When multiple sources could provide a type for a model attribute, the following priority order applies (highest first):

1. `#[TypefinderOverrides([...])]` attribute on the model class
2. Cast resolution (via `HasTypeDefinition` interface or built-in map)
3. Enum cast auto-detection (BackedEnum subclass → enum type reference)
4. Relationship introspection
5. DB schema column types (lowest priority)

---

## JSON Output Shape

Running `php artisan typefinder:generate --json` writes a single JSON object to stdout.

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
        { "path": "resources/js/types/models/Post.d.ts", "written": true },
        { "path": "resources/js/types/models/User.d.ts", "written": false }
    ],
    "warnings": [
        "Unknown cast type 'App\\Casts\\FooCast' — defaulting to unknown"
    ],
    "errors": []
}
```

| Field | Type | Description |
|---|---|---|
| `success` | `bool` | `false` if a fatal error occurred; inspect `errors` |
| `counts.models` | `int` | Number of model types generated |
| `counts.enums` | `int` | Number of enum types generated |
| `counts.requests` | `int` | Number of request types generated |
| `counts.pivots` | `int` | Number of pivot types generated |
| `files[].path` | `string` | Relative path of the generated file |
| `files[].written` | `bool` | `true` = file was rewritten (content changed); `false` = content unchanged, file left untouched |
| `warnings` | `string[]` | Non-fatal notices — surface these to the user (e.g. unknown cast type) |
| `errors` | `string[]` | Fatal errors that prevented generation |

If every `files[].written` is `false`, no PHP types changed in a way that affects frontend types — no TypeScript recheck is needed.

---

## Generated File Layout

```
{output_path}/              # default: resources/js/types/
├── models/
│   ├── {ModelName}.d.ts
│   └── index.d.ts          # barrel: re-exports all model types
├── enums/
│   ├── {EnumName}.d.ts
│   └── index.d.ts
├── requests/
│   ├── {RequestName}.d.ts
│   └── index.d.ts
├── pivots/
│   ├── {PivotName}.d.ts
│   └── index.d.ts
└── index.d.ts              # top-level barrel: re-exports all categories
```

Stale generated files (present on disk but no longer produced by the current run) are automatically pruned from managed output directories.
