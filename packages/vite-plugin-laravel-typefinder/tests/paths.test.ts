import { describe, expect, it } from 'vitest';
import { mkdirSync, mkdtempSync, realpathSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PathMapper, realpathLoose } from '../src/paths';

describe('PathMapper', () => {
    const mapper = new PathMapper({ '/var/www/html': '/host/project', '/var/www/html/vendor': '/host/vendor' });

    it('maps watcher paths to host paths', () => {
        expect(mapper.toHost('/var/www/html/app/Models')).toBe('/host/project/app/Models');
        expect(mapper.toHost('/var/www/html')).toBe('/host/project');
    });

    it('maps host paths to watcher paths', () => {
        expect(mapper.toWatcher('/host/project/app/Models/User.php')).toBe('/var/www/html/app/Models/User.php');
    });

    it('prefers the longest matching prefix', () => {
        expect(mapper.toHost('/var/www/html/vendor/x')).toBe('/host/vendor/x');
        expect(mapper.toWatcher('/host/vendor/x')).toBe('/var/www/html/vendor/x');
    });

    it('leaves paths no entry covers and partial segment matches unchanged', () => {
        expect(mapper.toHost('/srv/app')).toBe('/srv/app');
        expect(mapper.toHost('/var/www/htmlx/app')).toBe('/var/www/htmlx/app');
    });

    it('is the identity without a map', () => {
        expect(new PathMapper().toWatcher('/a/b.php')).toBe('/a/b.php');
    });
});

describe('realpathLoose', () => {
    it('resolves symlinks, also for files that no longer exist', () => {
        const base = realpathSync(mkdtempSync(join(tmpdir(), 'typefinder-')));
        try {
            mkdirSync(join(base, 'real/app'), { recursive: true });
            symlinkSync(join(base, 'real'), join(base, 'link'));

            expect(realpathLoose(join(base, 'link/app'))).toBe(join(base, 'real/app'));
            expect(realpathLoose(join(base, 'link/app/Gone/User.php'))).toBe(join(base, 'real/app/Gone/User.php'));
        } finally {
            rmSync(base, { recursive: true, force: true });
        }
    });
});
