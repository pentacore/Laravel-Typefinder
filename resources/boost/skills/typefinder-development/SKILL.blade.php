---
name: typefinder-development
description: Regenerate TypeScript type definitions when Laravel source that backs those types changes. Run after migrations, model edits, enum changes, form request rule changes, resource tweaks, or controller/event attribute additions.
license: MIT
metadata:
    author: Martin Claesson <contact@pentacore.se>
---
@php
    /** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Typefinder Development

## Documentation

The documentation for this project can be found either in the vendor directory pentacore/laravel-typefinder README files:
- pentacore/laravel-typefinder/README.md
- pentacore/laravel-typefinder/packages/laravel-typefinder/README.md
- pentacore/laravel-typefinder/packages/vite-plugin-typefinder/README.md

A deeper explanation of Typefinders workings can be found in the neighbouring REFERENCE file.

## Quick reference

### When to regenerate

Any of these changes should trigger a regenerate:

- A migration added, renamed, or retyped a column.
- A model changed `$casts`, `$hidden`, `$visible`, `$fillable`, `$guarded`, a relationship method, or a `#[TypefinderOverrides]` / `#[TypefinderWriteShape]` attribute.
- A `FormRequest`'s `rules()` or `#[TypefinderOverrides]` changed.
- A backed enum was added, renamed, or had cases changed.
- A `JsonResource` was added or its `#[TypefinderResource]` attribute changed.
- A controller action was tagged (or untagged) with `#[TypefinderPage]`.
- A class implementing `ShouldBroadcast` was added or its payload changed.
- A custom cast class was added/modified, or a `Typefinder::registerCast(...)` entry was added to a service provider.

### Generate Types

Run after any of the above criteria match if the vite plugin (`@pentacore/vite-plugin-laravel-typefinder`) isn't installed:
```bash
{{ $assist->artisanCommand('typefinder:generate --json') }}
```

The command emits a single JSON object on stdout. Relevant fields:

- `success` (bool) — if `false`, read `errors[]` and stop.
- `counts` — number of types generated per category (`models`, `enums`, `requests`, `resources`, `pages`, `broadcasting`).
- `files[]` — each entry has `{ path, written }`. `written: true` means the file changed. If every entry is `written: false`, nothing in the backend changed in a way that affects frontend types.
- `warnings[]` — non-fatal notices. Surface these to the user verbatim.

For a CI-style regression check (fails when regeneration would change anything on disk) use `--check` instead of `--json`. For a human-readable step-by-step trace use `--debug`.

### Import Patterns
@boostsnippet("Typefinder type import", "typescript")
// Named type imports
import type { Post } from '@typefinder/models/Post'
@endboostsnippet

## After running

- If frontend code imports from the regenerated types, verify it still type-checks (`tsc --noEmit` or the project's build/check script).
- If `warnings[]` is non-empty, relay them. A typical warning is "unknown cast type fell back to `unknown`" — the fix is either a `#[\Pentacore\Typefinder\Attributes\TypefinderCast('T')]` attribute on the cast class (if the user owns it) or a `Typefinder::registerCast(...)` call from a service provider (for third-party casts).
- Do **not** edit files under the output path (default `resources/js/typefinder/`). They are regenerated on every run and edits will be lost. The correct escape hatch is the `#[TypefinderOverrides([...])]` attribute on the source class.

## Debug mode

`{{$assist->artisanCommand('typefinder:generate --debug')}}` prints `[typefinder]`-prefixed lines describing each extraction step. Useful when a generate run aborts — the last `parsing category=… class=…` line in the output identifies the class that triggered the failure.
