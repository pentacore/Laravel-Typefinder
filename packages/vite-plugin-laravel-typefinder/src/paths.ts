import { realpathSync } from 'node:fs';
import { basename, dirname } from 'node:path';
import { normalisePath } from './matcher';

/**
 * The real path of `path` with symlinks resolved, like PHP's `realpath()` on
 * the watcher side. A path that no longer exists (a deleted file) resolves
 * through its nearest existing ancestor; a path with no existing ancestor is
 * returned as given.
 */
export const realpathLoose = (path: string): string => {
    const missing: string[] = [];
    let current = path;

    for (;;) {
        try {
            const real = normalisePath(realpathSync.native(current));
            return missing.length === 0 ? real : `${real}/${missing.reverse().join('/')}`;
        } catch {
            const parent = dirname(current);
            if (parent === current) {
                return normalisePath(path);
            }
            missing.push(basename(current));
            current = parent;
        }
    }
};

const replacePrefix = (path: string, from: string, to: string): string | null => {
    if (path === from) return to;
    if (path.startsWith(`${from}/`)) return to + path.slice(from.length);
    return null;
};

/**
 * Translates paths between the watcher's filesystem (e.g. `/var/www/html`
 * inside a Sail or Docker container) and the host Vite runs on.
 *
 * `pathMap` maps a watcher-side prefix to a host-side prefix. The longest
 * matching prefix wins; a path no entry covers is returned unchanged.
 */
export class PathMapper {
    private readonly entries: Array<{ remote: string; local: string }>;

    constructor(pathMap: Record<string, string> = {}) {
        this.entries = Object.entries(pathMap).map(([remote, local]) => ({
            remote: normalisePath(remote),
            local: realpathLoose(local),
        }));
    }

    toHost(path: string): string {
        return this.translate(normalisePath(path), 'remote', 'local');
    }

    toWatcher(path: string): string {
        return this.translate(normalisePath(path), 'local', 'remote');
    }

    private translate(path: string, from: 'remote' | 'local', to: 'remote' | 'local'): string {
        const sorted = [...this.entries].sort((a, b) => b[from].length - a[from].length);
        for (const entry of sorted) {
            const mapped = replacePrefix(path, entry[from], entry[to]);
            if (mapped !== null) return mapped;
        }
        return path;
    }
}
