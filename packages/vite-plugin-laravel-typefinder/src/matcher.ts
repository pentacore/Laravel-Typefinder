import picomatch from 'picomatch';

export const compileMatcher = (
    paths: string[],
): ((file: string) => boolean) => {
    if (paths.length === 0) {
        return () => false;
    }

    const globs = paths.map((p) => {
        const normalised = p.replace(/\\/g, '/').replace(/\/$/, '');
        return `${normalised}/**/*.php`;
    });

    const isMatch = picomatch(globs, { dot: true });

    return (file: string): boolean => {
        const normalised = file.replace(/\\/g, '/');
        return isMatch(normalised);
    };
};
