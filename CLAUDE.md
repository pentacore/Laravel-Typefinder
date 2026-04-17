# Project guidelines

## What this is

Laravel Typefinder — a Laravel package that scans Models, Enums, Casts, and Form Requests and emits matching TypeScript `.d.ts` definitions. Published as:

- Composer: `pentacore/laravel-typefinder` (Packagist; root `composer.json`).
- npm: `@pentacore/vite-plugin-laravel-typefinder` (npm registry; `packages/vite-plugin-laravel-typefinder/`).

Both are versioned in lockstep by semantic-release.

## Repo layout

```
.                                   root composer package lives here
├── packages/
│   ├── laravel-typefinder/src/     PHP source (namespace Pentacore\Typefinder\*)
│   │   ├── Commands/               typefinder:generate + typefinder:watch artisan commands
│   │   ├── Attributes/             class-level attributes (TypefinderOverrides, TypefinderWriteShape, TypefinderPage)
│   │   ├── Extractors/             Model / Enum / Request AST→metadata
│   │   ├── Renderers/              TypeScriptRenderer emits the .d.ts strings
│   │   └── Resolvers/              column / cast / morph resolvers
│   ├── laravel-typefinder/config/  default typefinder.php
│   └── vite-plugin-laravel-typefinder/  npm package (the Vite plugin)
├── tests/                          PHPUnit (Unit + Feature suites)
├── workbench/                      Testbench fixture Laravel app
│   ├── app/{Models,Enums,Http,Casts}  fixture classes for tests
│   └── database/migrations/        fixture migrations (SQLite :memory:)
├── scripts/sync-php-version.mjs    semantic-release → root composer.json + Version.php
└── docs/                           gitignored; local specs/plans live here
```

## Core conventions

- **Namespace:** `Pentacore\Typefinder\…` (psr-4 maps it to `packages/laravel-typefinder/src/`).
- **Minimum PHP:** 8.3 (8.2 was dropped in v1.0 via `feat!:`). PHP 8.5 pipe operator (`|>`) is **not** allowed in source — it parses only on 8.5 and breaks the matrix.
- **Laravel matrix tested in CI:** 11 / 12 / 13 × PHP 8.3 / 8.4 / 8.5 (minus L11+P8.5). See `.github/workflows/tests.yml`.
- **Local PHP may be 8.5+.** That means local `phpunit` passes version-specific code that CI rejects. When touching syntax, think "does this parse on 8.3?"
- **Test suite is Testbench-based.** Tests extend `Tests\TestCase` (`tests/TestCase.php`) which loads `workbench/database/migrations/` automatically. Use `workbench_path()` from `Orchestra\Testbench` to point at fixture files.
- **Feature suite vs Unit suite:** files under `tests/Feature/` are the Feature suite; everything else under `tests/` is the Unit suite. PHPUnit config is `phpunit.xml.dist`.

## Pre-commit checks (mandatory)

Run these from the repo root before `git commit`:

1. `vendor/bin/pint` — apply PHP code style fixes.
2. `vendor/bin/rector process` — apply Rector refactorings.
3. If any staged changes touch `packages/vite-plugin-laravel-typefinder/`, run `npm run lint` (root-level — uses the workspace) and fix issues.

Re-stage any files modified by these tools before committing. CI runs `--test` / `--dry-run` variants and fails on drift, so fixing locally is cheaper.

## Commit messages

- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`, `style:`, `perf:`, `build:`). `feat!:` or `BREAKING CHANGE:` for breaks.
- One-line subject, no extended body unless genuinely needed.
- **Never** add `Co-Authored-By` trailers.
- **Never** use `--no-verify`.

## Feature gating discipline

Gate behind an opt-in config flag **only** when the feature:

1. Depends on an optional third-party package or stack the user may not have installed (e.g. Inertia, Laravel Echo / broadcasting), **or**
2. Requires a newer Laravel version than the package's current minimum (so users on older Laravel don't regress).

Features that work for every supported Laravel version and don't need extra dependencies should just ship on. Adding a flag "just in case" is clutter — default-on is the right call when the feature is universally applicable.

Examples:
- Write-shape emission — pure Eloquent, works on every supported Laravel. Would not have needed a flag if added today (the existing `emit_write_shapes: false` is historical; leave it but don't treat it as precedent).
- Inertia page prop typing — gated, because users without Inertia shouldn't pay the AST-walk cost or get irrelevant config noise.
- Broadcasting / Echo events — gated, same reason.

## What gets emitted

Every generation run produces a tree under `output_path` (default `resources/js/typefinder/`). Categories:

| Category | Path | Default | Source |
| --- | --- | --- | --- |
| Models | `models/{Name}.d.ts` (includes `{Name}Create` + `{Name}Update`) | on | `app/Models/*` |
| Enums | `enums/{Name}.d.ts` (or `.ts` with `as const` values when `enums.emit_values` is on) | on | `app/Enums/*` (backed enums) |
| Form Requests | `requests/{Name}.d.ts` | on | `app/Http/Requests/*` |
| Pivots | `models/{Name}Pivot.d.ts` (alongside models — derived, not a separate category) | on | derived from `belongsToMany`/`morphToMany` |
| Resources | `resources/{Name}.d.ts` | on | `app/Http/Resources/*` (`JsonResource` subclasses) |
| Pages | `pages.d.ts` (single file) | **off** | controller actions tagged `#[TypefinderPage]` |
| Broadcasting | `broadcasting.d.ts` (single file) | **off** | classes implementing `ShouldBroadcast` |
| Helpers | `helpers.d.ts` (single file) | always | nine generic response wrappers (`WrappedResource`, `WrappedResourceCollection`, three resource paginators, `PaginatedModel` + `PaginationFields`, `ValidationErrorResponse`, `ErrorResponse`) — see README |
| Top-level barrel | `index.d.ts` | always | re-exports every emitted category |

Default-off categories are gated because they depend on optional Laravel features (Inertia, broadcasting). The rest default on per the gating rule above.

## Attributes

All under `Pentacore\Typefinder\Attributes\`. Detected via reflection; no trait inheritance involved.

- `#[TypefinderIgnore]` — skip any model/enum/request/resource/controller/event.
- `#[TypefinderOverrides(['col' => 'TSType'])]` — override or add fields on a model or form request.
- `#[TypefinderWriteShape(serverFilled: [...], respectMassAssignment: null, immutableOnUpdate: [...])]` — tune the Create/Update companion shapes.
- `#[TypefinderResource(shape / model / omit / extend)]` — declare a JSON resource's shape. Class-name convention (`UserResource` → `User`) is used when no attribute is present.
- `#[TypefinderPage(component, props, optional)]` — tag a controller action method as an Inertia page (repeatable).
- `#[TypefinderBroadcast(payload, as, channel, channelType)]` — override reflection for broadcast events with dynamic `broadcastOn()`/`broadcastWith()`.
- `#[TypefinderCast('{ … }')]` — declare the TS shape for a custom cast class you own. For third-party casts, use `Typefinder::registerCast()` from a service provider instead.

Workbench fixtures demonstrate each: `Post.php` (overrides), `Invoice.php` (write-shape), `Article.php`/`Product.php` (fillable/guarded), `LegacyModel.php` (ignore), `Http/Resources/*` (resources), `Http/Controllers/*` (pages), `Events/*` (broadcasting), `Casts/SettingsCast.php` (cast attribute).

## Cast type resolution

When a model uses `protected $casts = ['foo' => SomeCast::class]`, the generator resolves the TS shape in this order:

1. `Typefinder::registerCast(SomeCast::class, '…')` runtime registrations (facade-bound singleton).
2. `config('typefinder.casts.type_map')` array.
3. `#[TypefinderCast('…')]` attribute on `SomeCast`.
4. Built-in name/class map (`datetime`, `AsCollection`, etc.).
5. `BackedEnum` detection → emits a reference to the generated enum.
6. Fallback to `unknown` with a warning.

## Releases

- Semantic-release runs on every push to `master` after `Tests` is green. Config: `.releaserc.json`. Workflow: `.github/workflows/release.yml`.
- Version sync: `scripts/sync-php-version.mjs` writes the next version into root `composer.json` and `packages/laravel-typefinder/src/Version.php`. Root composer.json carries `"version"` so Packagist doesn't need a retag.
- Additive features (most of what we ship) → `feat:` → minor bump. Only platform floor changes or breaking type-output changes justify a major.
- Packagist is pinged via the `update-package` API after publish. The first package registration has to be done manually at https://packagist.org/packages/submit.

## Specs and plans

`docs/` is gitignored on purpose. Session-specific design docs and implementation plans live under:

- `docs/superpowers/specs/` — design specs (problem, approach, decisions).
- `docs/superpowers/plans/` — bite-sized implementation plans derived from specs.

These are working documents, not shipped artifacts. When writing them, don't assume they'll be readable by anyone outside the current session — they're for checkpointing, not for users.

Active roadmap: `docs/superpowers/specs/2026-04-14-typefinder-next-features.md` (write-shape split ✅ shipped, Inertia next, broadcasting after). Deferred backlog: `docs/superpowers/specs/2026-04-13-typefinder-deferred-backlog.md`.

## Safety notes (GitHub Actions)

Workflow files trigger a security reminder hook warning about command injection. The pattern to follow:

```yaml
env:
  SOMETHING: ${{ github.event.… }}
run: |
  echo "$SOMETHING"   # safe — never inline ${{ github.event.… }} in a run: block
```

Already applied in `release.yml`. Keep it that way when editing workflows.
