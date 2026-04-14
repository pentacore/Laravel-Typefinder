# Contributing

Thank you for considering a contribution to Laravel Typefinder!

## Getting started

```bash
git clone https://github.com/laravel-typefinder/laravel-typefinder.git
cd laravel-typefinder
composer install
npm install
```

The repository is a monorepo. The Composer package lives in `packages/laravel-typefinder/` and the Vite plugin in `packages/vite-plugin-laravel-typefinder/`. The `workbench/` directory contains the test Laravel application used by PHPUnit via Orchestra Testbench.

## Running tests

```bash
vendor/bin/phpunit
```

All tests must pass before submitting a pull request.

## Code style

**PHP — Pint:**

```bash
vendor/bin/pint
```

**PHP — Rector:**

```bash
vendor/bin/rector process
```

**TypeScript — ESLint:**

```bash
npm -w packages/vite-plugin-laravel-typefinder run lint
```

CI enforces `vendor/bin/pint --test` (no auto-fix) so run Pint locally before pushing.

## Workbench overview

`workbench/` is a minimal Laravel application used exclusively for integration tests. It contains sample Models, Enums, Casts, and Form Requests. If you add a new feature, add a corresponding fixture to `workbench/` and a test in `tests/`.

## Pull requests

- Keep changes focused — one feature or fix per PR.
- Include tests for any new behaviour.
- Reference any related issues in the PR description.
- Use [Conventional Commits](https://www.conventionalcommits.org/) — `CHANGELOG.md` is maintained automatically by the release pipeline (see below).

## Commit messages & releases

This repo releases automatically from `master` using [Conventional Commits](https://www.conventionalcommits.org/). Both the Composer package (`typefinder/laravel-typefinder`) and the npm package (`@pentacore/vite-plugin-laravel-typefinder`) are versioned in lockstep.

**Bump rules:**

| Commit prefix | Release |
| --- | --- |
| `feat:` | minor |
| `fix:`, `perf:` | patch |
| `…!:` or `BREAKING CHANGE:` footer | major |
| `chore:`, `docs:`, `refactor:`, `test:`, `ci:`, `build:`, `style:` | no release |

**Dry-run a release:** run the `Release` workflow from the Actions tab with `dry_run: true`.

**Rollback a bad release:**

1. `npm deprecate @pentacore/vite-plugin-laravel-typefinder@<version> "<reason>"`.
2. After owner approval, delete the git tag: `git push --delete origin v<version>`.
3. Edit `CHANGELOG.md` manually to note the retraction.
4. Land a `fix:` commit that replaces the broken behaviour; the next release proceeds normally.

## License

By contributing you agree that your contributions will be licensed under the MIT License.
