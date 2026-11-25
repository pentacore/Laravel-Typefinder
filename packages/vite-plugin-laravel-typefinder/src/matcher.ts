/**
 * Forward slashes, without trailing ones. The trailing slashes are trimmed in
 * a loop: the regex `/\/+$/` backtracks polynomially on long runs of `/`.
 */
export const normalisePath = (path: string): string => {
    const forward = path.replace(/\\/g, '/');
    let end = forward.length;
    while (end > 0 && forward[end - 1] === '/') {
        end--;
    }
    return forward.slice(0, end);
};

/**
 * Matches `.php` files under any of the given directories by plain prefix
 * comparison, so directory names that contain glob characters (`(`, `{`, `+`,
 * `[` …) match like any other.
 */
export const compileMatcher = (
    paths: string[],
): ((file: string) => boolean) => {
    const prefixes = paths
        .map(normalisePath)
        .filter((p) => p !== '')
        .map((p) => `${p}/`);

    if (prefixes.length === 0) {
        return () => false;
    }

    return (file: string): boolean => {
        const normalised = file.replace(/\\/g, '/');
        if (!normalised.endsWith('.php')) {
            return false;
        }
        return prefixes.some((prefix) => normalised.startsWith(prefix));
    };
};
