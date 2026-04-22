## [4.2.1](https://github.com/pentacore/Laravel-Typefinder/compare/v4.2.0...v4.2.1) (2026-04-22)

### Bug Fixes

* rollupTypes broke ([5e8ccb7](https://github.com/pentacore/Laravel-Typefinder/commit/5e8ccb748d12e7bea0ae6a04718330471a6199b7))

### Dependencies and Other Build Updates

* **deps-dev:** bump @semantic-release/exec from 6.0.3 to 7.1.0 ([27ebfa7](https://github.com/pentacore/Laravel-Typefinder/commit/27ebfa78421bce40de3b6221ed99d0c987da26d6))
* **deps-dev:** bump @semantic-release/github from 11.0.6 to 12.0.6 ([8b8adfd](https://github.com/pentacore/Laravel-Typefinder/commit/8b8adfddf0e43f5e818e47e53d16d8281fedd501))
* **deps-dev:** bump @semantic-release/npm from 12.0.2 to 13.1.5 ([1d0483a](https://github.com/pentacore/Laravel-Typefinder/commit/1d0483a99870edb9683a2b45299f7fb2ef6fedb6))
* **deps-dev:** bump conventional-changelog-conventionalcommits ([54a3a12](https://github.com/pentacore/Laravel-Typefinder/commit/54a3a126935d707eebfa627be22a7bc22110d77f))
* **deps-dev:** update orchestra/testbench requirement ([021d00a](https://github.com/pentacore/Laravel-Typefinder/commit/021d00a6576f56e3c73013d1478882fbb156b211))

## [4.2.0](https://github.com/pentacore/Laravel-Typefinder/compare/v4.1.0...v4.2.0) (2026-04-17)

### Features

* add --only= option to typefinder:generate for incremental regen ([7937f18](https://github.com/pentacore/Laravel-Typefinder/commit/7937f18fef1549e447dc0b5d55586ae33379c10e))
* add CacheKeyFactory for composer.lock and config hashing ([8c3c3c1](https://github.com/pentacore/Laravel-Typefinder/commit/8c3c3c1733aa136d70a65e0bce1c58ad76e0a9d5))
* add compileMatcher using picomatch ([7aa4353](https://github.com/pentacore/Laravel-Typefinder/commit/7aa435331168b0717390bcc5948c79ecf95ef289))
* add ExtractionCache with mtime/size and top-level hash invalidation ([5dcad4a](https://github.com/pentacore/Laravel-Typefinder/commit/5dcad4a19f838b4783a2ce29489b2b3ac739a1e3))
* add Generator::generatePaths() for incremental regeneration ([8dda433](https://github.com/pentacore/Laravel-Typefinder/commit/8dda433532236b763299ab025d38ccd9ab80f7d4))
* add ProtocolCodec for NDJSON wire format ([2013048](https://github.com/pentacore/Laravel-Typefinder/commit/201304863f6fe81fa8702edeb7d3e07f069b494a))
* add typefinder:watch skeleton with NDJSON handshake ([7deffdf](https://github.com/pentacore/Laravel-Typefinder/commit/7deffdfece7e5aba08817c26185aaddc4a8fbfe1))
* add Watcher class with handshake, regen dispatch, and coalesce ([b7ded34](https://github.com/pentacore/Laravel-Typefinder/commit/b7ded34536b6cd47aa61dd35dc69494251cd8626))
* clean SIGTERM/SIGINT shutdown in typefinder:watch ([3ef830e](https://github.com/pentacore/Laravel-Typefinder/commit/3ef830ef137c2521f2475a933642f08d727e9dda))
* populate extraction cache during full regen ([5964aff](https://github.com/pentacore/Laravel-Typefinder/commit/5964aff2afd9d8694dc169a2d7e448c9880a1524))
* rewrite Vite plugin to drive typefinder:watch via Watcher ([69e9435](https://github.com/pentacore/Laravel-Typefinder/commit/69e9435df4cee29ed5de307d0e9c1ba15f013d2f))
* typefinder:watch regen loop dispatches to Generator::generatePaths ([943079a](https://github.com/pentacore/Laravel-Typefinder/commit/943079a9ddebbd86f1967ea159e7aa4127fb3b37))

### Bug Fixes

* evict stale entries from ExtractionCache on load and persist ([1e740a1](https://github.com/pentacore/Laravel-Typefinder/commit/1e740a1101f178be652733b3022bbacc803eaf46))
* harden ExtractionCache persist and add schema version ([ee8b1f8](https://github.com/pentacore/Laravel-Typefinder/commit/ee8b1f8746877f88a1fc664093c9427024553c86))
* use stream_select in WatchCommand so SIGTERM interrupts the stdin loop ([47d1253](https://github.com/pentacore/Laravel-Typefinder/commit/47d125348598b45b27d197a4ea56a81dbb515f01))

### Dependencies and Other Build Updates

* add vitest + picomatch; define watcher wire protocol types ([b515be2](https://github.com/pentacore/Laravel-Typefinder/commit/b515be2fe58601b7b28d5be9094a41a2d9184bf3))
* switch vite plugin from unbuild to vite library mode ([f6d8367](https://github.com/pentacore/Laravel-Typefinder/commit/f6d83675fdb809afaa7d727794e10f1492ac2a57))

## [4.1.0](https://github.com/pentacore/Laravel-Typefinder/compare/v4.0.2...v4.1.0) (2026-04-17)

### Features

* warn when a model column type cannot be resolved to a TS type ([9a5b25d](https://github.com/pentacore/Laravel-Typefinder/commit/9a5b25d645ce00226543546a84a84ad5e217bf92))

### Bug Fixes

* avoid duplicate | null when an override or cast already includes null ([779be36](https://github.com/pentacore/Laravel-Typefinder/commit/779be36693fda109151015a584cbe30d3ea64531))
* emit Record<string, never> for empty broadcast/request types to satisfy eslint ([93585f9](https://github.com/pentacore/Laravel-Typefinder/commit/93585f9b56c3f0c2af5133a519797f06d01ef02f))
* emit Record<string, never> for fully guarded model Create/Update shapes ([b92a672](https://github.com/pentacore/Laravel-Typefinder/commit/b92a6723651af67b34b5bbfb96775f2da5910bef))
* make it more clear that the Paginated types are for resources, and add type for paginated models ([2798ee6](https://github.com/pentacore/Laravel-Typefinder/commit/2798ee6ba1932116c81ac236427ef416b40ce39b))
* recognise PostgreSQL pg_type names and MySQL int/year/bit/varbinary in column type map ([91b3f1d](https://github.com/pentacore/Laravel-Typefinder/commit/91b3f1d0fa4e0b309c17195850b67fb4e2a4f191))
* skip InteractsWithSockets-injected properties from broadcast event payloads ([72d0fc3](https://github.com/pentacore/Laravel-Typefinder/commit/72d0fc3405efbb60e13a44be516bd4d294b0aee4))

## [4.0.2](https://github.com/pentacore/Laravel-Typefinder/compare/v4.0.1...v4.0.2) (2026-04-16)

### Bug Fixes

* convert boost guidelines + skill to blade templates, wire up publish tags, minor cleanup ([f8994da](https://github.com/pentacore/Laravel-Typefinder/commit/f8994da165f7e75eb9b628c56366f6663e1e7a58))

## [4.0.1](https://github.com/pentacore/Laravel-Typefinder/compare/v4.0.0...v4.0.1) (2026-04-16)

### Bug Fixes

* move Laravel Boost guidelines to repo root for discovery ([f0cd27b](https://github.com/pentacore/Laravel-Typefinder/commit/f0cd27b166a9789e05759050c075735d678fb8ff))

## [4.0.0](https://github.com/pentacore/Laravel-Typefinder/compare/v3.0.1...v4.0.0) (2026-04-15)

### ⚠ BREAKING CHANGES

* ship vite plugin as ESM-only (drop CJS)
* fold pivots into models/ directory, drop pivots category

### Features

* opt-in as-const enum emission via typefinder.enums.emit_values ([b8d64f8](https://github.com/pentacore/Laravel-Typefinder/commit/b8d64f88ef52f26077fb257afbf885ffef42e00a))

### Bug Fixes

* harden NullSafeProxy, reset cast registry between tests, finally-clean check temp dir, cache resource model lookup ([4eb2ff1](https://github.com/pentacore/Laravel-Typefinder/commit/4eb2ff1003af3b6a9d51841bdfb15f1d8fd257a0))

### Code Refactoring

* fold pivots into models/ directory, drop pivots category ([ec43a72](https://github.com/pentacore/Laravel-Typefinder/commit/ec43a7262d7a9b3c8e3328e2891ef44b7c189735))
* ship vite plugin as ESM-only (drop CJS) ([f423c8d](https://github.com/pentacore/Laravel-Typefinder/commit/f423c8d1e531f333f8c6e05b10ce0a89fb510741))

## [3.0.1](https://github.com/pentacore/Laravel-Typefinder/compare/v3.0.0...v3.0.1) (2026-04-14)

### Bug Fixes

* publish npm package via OIDC trusted publisher instead of NPM_TOKEN ([7cb6344](https://github.com/pentacore/Laravel-Typefinder/commit/7cb63441bddbe8f52ee3eb7d68dda3f096d8ac5a))

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
- Laravel Boost guidelines and Claude Code skill at repo-root `resources/boost/`.
