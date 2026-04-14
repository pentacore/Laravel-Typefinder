# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-04-13

### Added
- Initial release.
- `typefinder:generate` artisan command with `--debug` and `--json` output flags.
- Model type generation with DB schema, `$casts`, relationships (including polymorphic with generics), `$visible`/`$hidden` filtering, and `HasTypeOverrides` trait for manual overrides.
- Backed enum type generation (string and integer backing).
- Form Request type generation with rule mapping (string/number/boolean/array/file), `Rule::enum()`, `Rule::in()` → literal unions, `Rule::anyOf()` unions (Laravel 13+), `confirmed` field auto-generation, and nested object support.
- Pivot type generation for `belongsToMany` and `morphToMany` relationships.
- Barrel `index.d.ts` files per category and at the top level.
- `HasTypeDefinition` contract for custom cast type shapes.
- Selective-write: files are only rewritten when content changes; stale files are pruned.
- Vite plugin `@pentacore/vite-plugin-laravel-typefinder` with debounced HMR and single-flight queued runs.
- Laravel Boost guidelines auto-discovered from `resources/boost/guidelines/core.blade.php`.
- Claude Code skill publishable via `vendor:publish --tag=typefinder-skill`.
