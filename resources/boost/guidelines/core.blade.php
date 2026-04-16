@php
    /** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Typefinder

This project uses `pentacore/laravel-typefinder` to auto-generate TypeScript type definitions from Laravel source. Generated files live under the configured `typefinder.output_path` (default: `resources/js/typefinder/`) and are kept in sync by running `{{$assist->artisanCommand('typefinder:generate')}}` — either manually, via the companion Vite plugin, or from CI.

## Config

The user can publish a config file with `{{$assist->artisanCommand('vendor:publish --tag=typefinder-config')}}`.
The values are available under the `typefinder` key.

## What gets emitted

@verbatim
    - **Models** (`{{$assist->appPath('Models/*')}}`) → `{output_path}/models/{Name}.d.ts`. Respects `$hidden` / `$visible`. Uses `$casts` plus DB schema. Relationships are emitted as optional fields. Each file also contains `{Name}Create` and `{Name}Update` companion types (unless `typefinder.models.emit_write_shapes` is disabled).
        - **Pivots** — auto-derived from `belongsToMany` / `morphToMany` relationships. Written into `{output_path}/models/{Name}Pivot.d.ts` alongside the models they connect.
    - **Enums** (`{{$assist->appPath('Enums/*')}}`) → `{output_path}/enums/{Name}.d.ts`. Backed enums only. Setting `enums.emit_values: true` switches the output to `.ts` files with both an `as const` object (for runtime iteration) and the matching union type.
    - **Form Requests** (`{{$assist->appPath('Http/Requests/*')}}`) → `{output_path}/requests/{Name}.d.ts`. Extracted from `rules()` with null-safe proxy recovery for rules that touch request context at runtime.
    - **Resources** (`{{$assist->appPath('Http/Resources/*')}}`) → `{output_path}/resources/{Name}.d.ts`. Declared via `#[TypefinderResource]` with an explicit shape, a model extension (`Omit<Model, ...> & { ... }`), or name-convention passthrough (`{Model}Resource` → `{Model}`).
    - **Pages** (opt-in, `typefinder.inertia.enabled`, `{{$assist->appPath('Http/Controllers/*')}}`) → `{output_path}/pages.d.ts`. A single file with a `PageProps` map keyed by Inertia component name. Tag controller actions with `#[TypefinderPage(component: ..., props: [...])]`.
    - **Broadcasting** (opt-in, `typefinder.broadcasting.enabled`, `{{$assist->appPath('Events/*')}}`) → `{output_path}/broadcasting.d.ts`. Public/private/presence channel maps plus a flat `BroadcastEvents` map. Discovered from classes implementing `ShouldBroadcast`.
    - **Helpers** (always emitted) → `{output_path}/helpers.d.ts`. Generic response wrappers: `Wrapped<T>`, `WrappedCollection<T>`, `PaginatedCollection<T>`, `CursorPaginatedCollection<T>`, `SimplePaginatedCollection<T>`, `ValidationErrorResponse`, `ErrorResponse`.
    - **Top-level barrel** → `{output_path}/index.d.ts`. Re-exports every active category.
@endverbatim
## Custom cast types
                        
For casts you own, tag the class:

```php
use Pentacore\Typefinder\Attributes\TypefinderCast;

#[TypefinderCast('{ theme: string; notifications: boolean }')]
class SettingsCast implements CastsAttributes { /* get() / set() */ }
```

For casts you can't modify (third-party packages), register them from a service provider:

```php
use Pentacore\Typefinder\Facades\Typefinder;

Typefinder::registerCast(\Spatie\MediaLibrary\Cast::class, 'Media[]');
```

`Typefinder::registerCast()` accepts a closure as the second argument when the type depends on runtime config.
