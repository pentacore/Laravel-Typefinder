## [1.0.1](https://github.com/pentacore/Laravel-Typefinder/compare/v1.0.0...v1.0.1) (2026-04-14)

### Bug Fixes

* publish vite plugin with public access for scoped package ([66d48b2](https://github.com/pentacore/Laravel-Typefinder/commit/66d48b2220924993b80cdc675758c9f11c74089b))
* set publishConfig.access=public for scoped npm package ([1d63678](https://github.com/pentacore/Laravel-Typefinder/commit/1d63678e7341e33b05167e4ee6c5d054161d5fc2))

## 1.0.0 (2026-04-14)

### Features

* add sync-php-version script ([a6eaad7](https://github.com/pentacore/Laravel-Typefinder/commit/a6eaad7d8dacd85c6997e82b08885bbcf8a5ad13))
* add Version class ([00e36f1](https://github.com/pentacore/Laravel-Typefinder/commit/00e36f1719c75de17bf0d044fd25b5bbb7731894))

### Bug Fixes

* cleanup code ([01d3bc0](https://github.com/pentacore/Laravel-Typefinder/commit/01d3bc0a9db105f6fa56a204e30930cabc607e42))
* no need to publish boost files ([512451d](https://github.com/pentacore/Laravel-Typefinder/commit/512451d6dda90bc6a702fc211896c424acb1f33d))
* package-lock.json shouldnt be ignored ([cf5f03e](https://github.com/pentacore/Laravel-Typefinder/commit/cf5f03ec67dbf5d5f16fbcc4a8fe9eb9dbb8039d))
* replace PHP 8.5 pipe operator with nested calls for 8.2+ compat ([1be3ca6](https://github.com/pentacore/Laravel-Typefinder/commit/1be3ca6bfef9e6d2e57d58c9359844d7bf27fc73))

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
