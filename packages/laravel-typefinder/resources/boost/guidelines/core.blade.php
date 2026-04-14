## Laravel Typefinder

This project uses `laravel-typefinder` to auto-generate TypeScript `.d.ts` definitions from Laravel Models, Enums, Form Requests, API Resources, Inertia controllers, broadcast events, and pivot tables. Types are written to the `output_path` configured in `config/typefinder.php` (default: `resources/js/typefinder/`).

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

For verbose line-oriented debug output use `--debug`. For CI drift-checking use `--check` (exits non-zero when on-disk output is stale).

### What gets generated

- **Models** (`app/Models/*`) → `{output_path}/models/<Name>.d.ts`. Respects `$hidden` / `$visible`. Uses `$casts` plus DB schema. Relationships are emitted as optional fields. Each file also contains `{Model}Create` and `{Model}Update` companion types.
- **Enums** (`app/Enums/*`) → `{output_path}/enums/<Name>.d.ts`. Backed enums only.
- **Form Requests** (`app/Http/Requests/*`) → `{output_path}/requests/<Name>.d.ts`. Extracted from `rules()` with null-safe proxy recovery for runtime-dependent rules.
- **Pivots** — auto-derived from `belongsToMany` / `morphToMany` relationships.
- **Resources** (`app/Http/Resources/*`) → `{output_path}/resources/<Name>.d.ts`. Three declaration tiers: `#[TypefinderResource(shape: [...])]`, `#[TypefinderResource(model: …, omit, extend)]`, or `{Model}Resource` name convention.
- **Pages** (opt-in) → `{output_path}/pages.d.ts`. A `PageProps` map keyed by Inertia component name; declared per controller action with `#[TypefinderPage(component: ..., props: [...])]`.
- **Broadcasting** (opt-in) → `{output_path}/broadcasting.d.ts`. `BroadcastPublicChannels` / `BroadcastPrivateChannels` / `BroadcastPresenceChannels` / `BroadcastEvents` maps, discovered from classes implementing `ShouldBroadcast`.
- **Helpers** (always emitted) → `{output_path}/helpers.d.ts`. Generic response wrappers: `Wrapped<T>`, `WrappedCollection<T>`, `PaginatedCollection<T>`, `CursorPaginatedCollection<T>`, `SimplePaginatedCollection<T>`, `ValidationErrorResponse`, `ErrorResponse`.

### Attributes

All live under `\Pentacore\Typefinder\Attributes\`:

- `#[TypefinderIgnore]` — skip a class.
- `#[TypefinderOverrides(['col' => 'T'])]` — override field types on models / form requests.
- `#[TypefinderWriteShape(serverFilled, respectMassAssignment, immutableOnUpdate)]` — tune Create/Update shapes on models.
- `#[TypefinderResource(shape / model / omit / extend)]` — declare a JSON resource's shape.
- `#[TypefinderPage(component, props, optional)]` — tag controller actions for Inertia page types.
- `#[TypefinderBroadcast(payload, as, channel, channelType)]` — override reflection for broadcast events.
- `#[TypefinderCast('T')]` — declare the TS shape for a custom cast class.

### Custom cast types

For casts you own, use the attribute:

```php
use Pentacore\Typefinder\Attributes\TypefinderCast;

#[TypefinderCast('{ theme: string; notifications: boolean }')]
class SettingsCast implements CastsAttributes
{
    // ... get() / set()
}
```

For third-party casts you can't modify, register them from a service provider:

```php
use Pentacore\Typefinder\Facades\Typefinder;

Typefinder::registerCast(\Spatie\MediaLibrary\Cast::class, 'Media[]');
```

### When to regenerate

Regenerate types whenever you:
- Add or rename a migration column
- Add/change `$casts` on a model
- Add or change `$hidden` / `$visible`
- Add an enum or change its cases
- Add or change a FormRequest's rules
- Add or change a relationship method
- Add or change a JsonResource
- Tag a controller action with `#[TypefinderPage]` or an event with `ShouldBroadcast`

The Vite plugin `@pentacore/vite-plugin-laravel-typefinder` runs this command automatically on build and on file changes during dev.
