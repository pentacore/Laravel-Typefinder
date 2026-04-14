## [3.0.0](https://github.com/pentacore/Laravel-Typefinder/compare/v2.0.0...v3.0.0) (2026-04-14)

### ⚠ BREAKING CHANGES

* replace HasTypeDefinition contract with TypefinderCast attribute + facade registry

### Features

* add --check flag to typefinder:generate ([9598bad](https://github.com/pentacore/Laravel-Typefinder/commit/9598baddd43cd0d262475dd67f0029844f766f22))
* add ResourceExtractor with three declaration tiers ([64aa65a](https://github.com/pentacore/Laravel-Typefinder/commit/64aa65affdf91412727c22daa0959c57b36c78c3))
* add TypefinderResource attribute ([4c32c60](https://github.com/pentacore/Laravel-Typefinder/commit/4c32c60cce5bcd5b4eecd5842f494b2399bf3462))
* render JSON resources and response-helper types ([018d1fd](https://github.com/pentacore/Laravel-Typefinder/commit/018d1fd6b4ac76411c77f77c5c684c1b9f6dee26))
* wire resource extraction and always-on helpers emission ([2ced7d2](https://github.com/pentacore/Laravel-Typefinder/commit/2ced7d2d192ccefe7c5baebd7ffd6b9f7a68e280))

### Code Refactoring

* replace HasTypeDefinition contract with TypefinderCast attribute + facade registry ([b7283e5](https://github.com/pentacore/Laravel-Typefinder/commit/b7283e5439f4d3c782ebf0d23b1229d92a84567d))

## [2.0.0](https://github.com/pentacore/Laravel-Typefinder/compare/v1.3.0...v2.0.0) (2026-04-14)

### ⚠ BREAKING CHANGES

* remove HasTypeOverrides and HasWriteShapeContract traits

### Features

* add BroadcastExtractor with ShouldBroadcast discovery ([5836066](https://github.com/pentacore/Laravel-Typefinder/commit/5836066831f160583eaef84a9ed318b5d135402a))
* add ControllerExtractor for TypefinderPage attributes ([1898df1](https://github.com/pentacore/Laravel-Typefinder/commit/1898df151ca3efe6b9ff68f622b4197241d24b49))
* add TypefinderBroadcast attribute ([40b01a0](https://github.com/pentacore/Laravel-Typefinder/commit/40b01a0bd4e65823cf46bac0b2176f4a275ecd58))
* add TypefinderIgnore attribute to skip classes from generation ([1913d3e](https://github.com/pentacore/Laravel-Typefinder/commit/1913d3edc5bd1e34a6ee7ee7ae675e97a3c57c1c))
* add TypefinderOverrides and TypefinderWriteShape attributes ([600fc65](https://github.com/pentacore/Laravel-Typefinder/commit/600fc65f881c8b553176e5bbc9862db1057d8b80))
* add TypefinderPage attribute ([927df74](https://github.com/pentacore/Laravel-Typefinder/commit/927df745940b2bbbbb7092f0b57f2e6570f15839))
* honour TypefinderOverrides attribute on FormRequests ([ce26b5f](https://github.com/pentacore/Laravel-Typefinder/commit/ce26b5f7ceb5138b8d3051cc800186eaa0cf89a0))
* move default output_path to resources/js/typefinder ([e93f30d](https://github.com/pentacore/Laravel-Typefinder/commit/e93f30d6f5e945dd240c5b188cec530bfb5f4d8f))
* recover form request rules() via null-safe proxy with overrides fallback ([e43d1f9](https://github.com/pentacore/Laravel-Typefinder/commit/e43d1f91a92e190dc1dd85cf07ca53579fa32da4))
* render four broadcasting type maps ([de5dbf2](https://github.com/pentacore/Laravel-Typefinder/commit/de5dbf273f845c6fccf994a65517800fb3ef2996))
* render PageProps map from extracted page metadata ([6518af3](https://github.com/pentacore/Laravel-Typefinder/commit/6518af3cf3b65f76ff75c56d8f415c5d802af137))
* skip FormRequests whose rules() throws; emit warning instead ([bf184e7](https://github.com/pentacore/Laravel-Typefinder/commit/bf184e7b83dd24dbe0ea868aff669236520ead30))
* wire broadcasting extraction and writeBroadcasting in GenerateCommand ([95398fc](https://github.com/pentacore/Laravel-Typefinder/commit/95398fc518609fc3b04c997f122ea2c16896fa17))
* wire inertia extraction and writePages in GenerateCommand ([c382a6a](https://github.com/pentacore/Laravel-Typefinder/commit/c382a6a01d664b11e148364ab1af46e55755afc5))

### Code Refactoring

* remove HasTypeOverrides and HasWriteShapeContract traits ([37b8c54](https://github.com/pentacore/Laravel-Typefinder/commit/37b8c541c6d4b6d5d71bb463baa8b94dbbee7940))

## [1.3.0](https://github.com/pentacore/Laravel-Typefinder/compare/v1.2.0...v1.3.0) (2026-04-14)

### Features

* auto-append output path to .gitignore ([6c1003e](https://github.com/pentacore/Laravel-Typefinder/commit/6c1003e642fc2ff046e0f6e3d65121aa9a36c373))
* emit model Create/Update types in the same file as the read shape ([c6b5ebc](https://github.com/pentacore/Laravel-Typefinder/commit/c6b5ebc22b932bf4e4d713105a338876efc9b478))
* log class being parsed in debug mode for all extractors ([35a65b3](https://github.com/pentacore/Laravel-Typefinder/commit/35a65b3c95d3d877aebb86620b841e61223944f0))
* prepend auto-generated header to every emitted .d.ts file ([422c920](https://github.com/pentacore/Laravel-Typefinder/commit/422c92029e22021c77b4923c5f3b944ca900b0a5))

## [1.2.0](https://github.com/pentacore/Laravel-Typefinder/compare/v1.1.0...v1.2.0) (2026-04-14)

### Features

* add HasWriteShapeContract trait ([a14afb9](https://github.com/pentacore/Laravel-Typefinder/commit/a14afb931fd7d0e2a1633bdbaf59fa258cf822b1))
* add write-shape config keys ([33b4258](https://github.com/pentacore/Laravel-Typefinder/commit/33b4258caa52ffbab764d3e2fa623d936691f59d))
* emit Create/Update companion files when enabled ([7f339a5](https://github.com/pentacore/Laravel-Typefinder/commit/7f339a53d6264dc964a3f54026d9d8bc0436aff6))
* extract is_primary, is_server_filled, assignable_columns metadata ([450a511](https://github.com/pentacore/Laravel-Typefinder/commit/450a5118f03f747936f9a6368f66b7205d57ec49))
* render Create and Update companion types ([f261da1](https://github.com/pentacore/Laravel-Typefinder/commit/f261da123bdf94ff1764e42087146046d014a956))

### Bug Fixes

* fix docs ([e2dee63](https://github.com/pentacore/Laravel-Typefinder/commit/e2dee63c2600ac96b251ffdcbf4888e8606a5c7e))
* make write models default on ([f0d4fde](https://github.com/pentacore/Laravel-Typefinder/commit/f0d4fdeb920cd80653d8bf40e557a9ce68e1113f))

## [1.1.0](https://github.com/pentacore/Laravel-Typefinder/compare/v1.0.1...v1.1.0) (2026-04-14)

### Features

* drop support for PHP 8.2 ([0fa585f](https://github.com/pentacore/Laravel-Typefinder/commit/0fa585f4249361503e6b670eff093f812193afd3))
* sync composer.json version from semantic-release ([8f98520](https://github.com/pentacore/Laravel-Typefinder/commit/8f985205c29e78c8b0c918c4a01162e0cb5fb2f0))

### Bug Fixes

* update url's to match github repo ([2cde0d1](https://github.com/pentacore/Laravel-Typefinder/commit/2cde0d13f3df42bb83105f16602fc61b7a3b5f4b))

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
