import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { syncPhpVersion, syncComposerVersion } from './sync-php-version.mjs';

test('rewrites VERSION constant to the provided version', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sync-ver-'));
    const file = join(dir, 'Version.php');
    writeFileSync(file, `<?php
namespace Pentacore\\Typefinder;
final class Version { public const VERSION = '0.1.0'; }
`);

    syncPhpVersion({ file, version: '1.2.3' });

    const out = readFileSync(file, 'utf8');
    assert.match(out, /public const VERSION = '1\.2\.3';/);
    rmSync(dir, { recursive: true });
});

test('throws when VERSION constant is missing', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sync-ver-'));
    const file = join(dir, 'Version.php');
    writeFileSync(file, `<?php\nclass X {}\n`);

    assert.throws(() => syncPhpVersion({ file, version: '1.2.3' }), /VERSION constant not found/);
    rmSync(dir, { recursive: true });
});

test('rejects non-semver input', () => {
    assert.throws(() => syncPhpVersion({ file: '/nope', version: 'not-a-version' }), /invalid version/i);
});

test('syncComposerVersion writes version field into composer.json', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sync-ver-'));
    const file = join(dir, 'composer.json');
    writeFileSync(file, JSON.stringify({ name: 'foo/bar', require: { php: '^8.2' } }, null, 4) + '\n');

    syncComposerVersion({ file, version: '1.2.3' });

    const parsed = JSON.parse(readFileSync(file, 'utf8'));
    assert.equal(parsed.version, '1.2.3');
    assert.equal(parsed.name, 'foo/bar');
    rmSync(dir, { recursive: true });
});

test('syncComposerVersion rejects non-semver input', () => {
    assert.throws(() => syncComposerVersion({ file: '/nope', version: 'bad' }), /invalid version/i);
});
