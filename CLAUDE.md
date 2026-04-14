# Project guidelines

## Pre-commit checks (mandatory)

Before running `git commit` in this repo, always run these from the repo root:

1. `vendor/bin/pint` — apply PHP code style fixes.
2. `vendor/bin/rector process` — apply Rector refactorings.
3. If any staged changes touch `packages/vite-plugin-laravel-typefinder/`, also run `npm run lint -w packages/vite-plugin-laravel-typefinder` (or `npm run lint` from root — same workspace) and fix issues before committing.

Re-stage any files modified by these tools, then commit. CI runs the `--test` / `--dry-run` variants and will fail the build on any drift, so catching it locally is cheaper.

## Commit messages

- Conventional Commits format (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`, `style:`, `perf:`, `build:`). `feat!:` or a `BREAKING CHANGE:` footer for breaking changes.
- One-line subject, no extended body unless the change genuinely needs explanation.
- Never add `Co-Authored-By` trailers.
- Never use `--no-verify`.

## Releases

Semantic-release runs automatically on every push to `master` — see `.releaserc.json` and `.github/workflows/release.yml`. `feat:` → minor, `fix:`/`perf:` → patch, `!`/`BREAKING CHANGE:` → major, everything else → no release.
