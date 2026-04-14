#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/;
const CONST_RE = /public const VERSION = '[^']*';/;

export function syncPhpVersion({ file, version }) {
    if (!SEMVER.test(version)) {
        throw new Error(`invalid version: ${version}`);
    }
    const src = readFileSync(file, 'utf8');
    if (!CONST_RE.test(src)) {
        throw new Error(`VERSION constant not found in ${file}`);
    }
    const next = src.replace(CONST_RE, `public const VERSION = '${version}';`);
    writeFileSync(file, next);
}

export function syncComposerVersion({ file, version }) {
    if (!SEMVER.test(version)) {
        throw new Error(`invalid version: ${version}`);
    }
    const src = readFileSync(file, 'utf8');
    const parsed = JSON.parse(src);
    parsed.version = version;
    const indent = src.match(/^(\s+)"/m)?.[1] ?? '    ';
    const trailingNewline = src.endsWith('\n') ? '\n' : '';
    writeFileSync(file, JSON.stringify(parsed, null, indent) + trailingNewline);
}

const isMain = import.meta.url === `file://${process.argv[1]}`;
if (isMain) {
    const version = process.argv[2];
    if (!version) {
        console.error('usage: sync-php-version.mjs <version>');
        process.exit(1);
    }
    const phpFile = resolve(process.cwd(), 'packages/laravel-typefinder/src/Version.php');
    const composerFile = resolve(process.cwd(), 'packages/laravel-typefinder/composer.json');
    syncPhpVersion({ file: phpFile, version });
    syncComposerVersion({ file: composerFile, version });
    console.log(`synced ${phpFile} and ${composerFile} → ${version}`);
}
