---
name: typefinder
description: Use when you need to regenerate Laravel Typefinder TypeScript definitions after changing Laravel Models, Enums, Casts, Form Requests, or migrations. Runs the `typefinder:generate` artisan command and interprets its structured JSON output.
---

# Typefinder

Use this skill to regenerate TypeScript type definitions from the Laravel backend.

## When to use

Trigger this skill when any of the following change:
- Eloquent models (new model, new `$casts` entry, new `$hidden`/`$visible`, new relationship method, new `typeOverrides()` entry)
- Backed PHP enums under `app/Enums` (new case, new enum)
- FormRequest `rules()` methods
- Migrations that change column types, add/remove columns, or change nullability
- Custom Cast classes tagged with `#[\Pentacore\Typefinder\Attributes\TypefinderCast('…')]`, or registered via `Typefinder::registerCast(...)` for third-party casts
- JsonResource subclasses (`app/Http/Resources/*`), controller actions tagged `#[TypefinderPage]` (opt-in), broadcast events (opt-in)

## How to run

Run the command with JSON output so you can parse the result:

```bash
php artisan typefinder:generate --json
```

The stdout will be a single JSON object. Parse it. Relevant fields:

- `success` (bool) — if false, read `errors` and stop.
- `counts` — number of generated types per category.
- `files[]` — each entry has `{ path, written }`. `written: true` means the file changed. If every entry is `written: false`, nothing in the backend changed in a way that affects frontend types.
- `warnings[]` — non-fatal notices (e.g. unknown cast type fell back to `unknown`). Surface these to the user.

## After running

- If any frontend code imports from the regenerated types, verify it still type-checks. Run the project's TypeScript check (typically `tsc --noEmit` or the build script).
- If `warnings` is non-empty, mention them to the user — an unknown cast type usually means the cast needs a `#[TypefinderCast('…')]` attribute or a `Typefinder::registerCast(...)` entry in a service provider.
- Do not manually edit files under the output path (default `resources/js/typefinder/`) — they are regenerated on each run and edits will be lost. Apply `#[TypefinderOverrides([...])]` on the model class instead.

## Debug mode

For troubleshooting, use `--debug` instead of `--json`. You'll get line-oriented output prefixed with `[typefinder]`.
