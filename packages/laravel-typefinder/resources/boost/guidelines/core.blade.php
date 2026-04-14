## Laravel Typefinder

This project uses `laravel-typefinder` to auto-generate TypeScript `.d.ts` definitions from Laravel Models, Enums, Form Requests, and Casts. Types are written to the `output_path` configured in `config/typefinder.php` (default: `resources/js/types/`).

### Generate types

Run:
```
php artisan typefinder:generate
```

For agent-friendly structured output use `--json`:
```
php artisan typefinder:generate --json
```

The JSON response contains: `success`, `duration_ms`, `output_path`, `counts`, `files` (each with `path` and `written` boolean), `warnings`, `errors`. Parse it to decide whether to continue or abort.

For verbose line-oriented debug output use `--debug`:
```
php artisan typefinder:generate --debug
```

### What gets generated

- **Models** (`app/Models/*`) → `{output_path}/models/<Name>.d.ts`. Respects `$hidden` / `$visible`. Uses `$casts` plus DB schema. Relationships are emitted as optional fields. Use `#[\Pentacore\Typefinder\Attributes\TypefinderOverrides([...])]` on the model class to override a field's TypeScript type or add virtual fields, and `#[\Pentacore\Typefinder\Attributes\TypefinderWriteShape(...)]` to tune Create/Update shapes.
- **Enums** (`app/Enums/*`) → `{output_path}/enums/<Name>.d.ts`. Backed enums only. String union for string-backed, integer union for int-backed.
- **Form Requests** (`app/Http/Requests/*`) → `{output_path}/requests/<Name>.d.ts`. Extracted from `rules()`. Supports `Rule::enum()`, `Rule::in()`, `Rule::anyOf()`, `confirmed`, nested `a.b` keys, `a.*` wildcards.
- **Pivots** — auto-derived from `belongsToMany` / `morphToMany` relationships.

### Custom cast type definitions

Custom cast classes can implement `\Pentacore\Typefinder\Contracts\HasTypeDefinition` to provide their own TypeScript shape:

```php
use Pentacore\Typefinder\Contracts\HasTypeDefinition;

class SettingsCast implements CastsAttributes, HasTypeDefinition
{
    public static function typeDefinition(): string
    {
        return '{ theme: string; notifications: boolean }';
    }
}
```

### When to regenerate

Regenerate types whenever you:
- Add or rename a migration column
- Add/change `$casts` on a model
- Add or change a `$hidden` / `$visible` array
- Add an enum or change an enum's cases
- Add or change a FormRequest's validation rules
- Add or change a relationship method

The Vite plugin `@pentacore/vite-plugin-laravel-typefinder` runs this command automatically on build and on file changes during dev.
