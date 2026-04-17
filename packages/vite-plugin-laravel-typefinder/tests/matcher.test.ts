import { describe, expect, it } from 'vitest';
import { compileMatcher } from '../src/matcher';

describe('compileMatcher', () => {
    it('returns true for files under a watched directory', () => {
        const match = compileMatcher(['/app/Models']);
        expect(match('/app/Models/User.php')).toBe(true);
        expect(match('/app/Models/Nested/Thing.php')).toBe(true);
    });

    it('returns false for files outside every watched directory', () => {
        const match = compileMatcher(['/app/Models']);
        expect(match('/app/Http/Controllers/UserController.php')).toBe(false);
        expect(match('/tmp/foo.php')).toBe(false);
    });

    it('handles multiple watched directories', () => {
        const match = compileMatcher(['/app/Models', '/app/Enums']);
        expect(match('/app/Models/A.php')).toBe(true);
        expect(match('/app/Enums/B.php')).toBe(true);
        expect(match('/app/Other/C.php')).toBe(false);
    });

    it('only matches .php files', () => {
        const match = compileMatcher(['/app/Models']);
        expect(match('/app/Models/User.php')).toBe(true);
        expect(match('/app/Models/User.ts')).toBe(false);
        expect(match('/app/Models/README.md')).toBe(false);
    });

    it('returns false when given no paths', () => {
        const match = compileMatcher([]);
        expect(match('/app/Models/User.php')).toBe(false);
    });

    it('normalises Windows-style separators in the input file', () => {
        const match = compileMatcher(['/app/Models']);
        expect(match('\\app\\Models\\User.php')).toBe(true);
    });
});
