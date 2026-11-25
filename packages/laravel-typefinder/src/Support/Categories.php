<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use InvalidArgumentException;

/**
 * The source categories of `config('typefinder')`: which exist, which are
 * enabled and which directories each one reads. The generator, the watch
 * handshake and class discovery all ask here, so they agree.
 */
final class Categories
{
    /**
     * Config keys, in extraction order: resources refer to the models and
     * enums extracted before them.
     */
    public const array ALL = ['enums', 'models', 'requests', 'resources', 'inertia', 'broadcasting'];

    /**
     * Where each category is emitted under the output path: a directory with
     * a file per type and a barrel, or a single `{name}.d.ts` file. The
     * names are also what the top-level barrel re-exports.
     *
     * @var array<string, array{0: 'directory'|'file', 1: string}>
     */
    public const array OUTPUT = [
        'enums' => ['directory', 'enums'],
        'models' => ['directory', 'models'],
        'requests' => ['directory', 'requests'],
        'resources' => ['directory', 'resources'],
        'inertia' => ['file', 'pages'],
        'broadcasting' => ['file', 'broadcasting'],
    ];

    /** Off unless the config turns them on: they need Inertia or broadcasting. */
    private const array OPT_IN = ['inertia', 'broadcasting'];

    public static function enabled(string $category): bool
    {
        return (bool) config(sprintf('typefinder.%s.enabled', $category), ! in_array($category, self::OPT_IN, true));
    }

    /**
     * The directories a category reads, as a list: string keys (type-name
     * prefixes, see {@see self::prefix()}) are dropped.
     *
     * @return list<string> real paths
     */
    public static function paths(string $category): array
    {
        return array_map(
            static fn (mixed $dir): string => self::realPath((string) $dir),
            array_values((array) config(sprintf('typefinder.%s.paths', $category), [])),
        );
    }

    /**
     * The type-name prefix of a file: the string key its directory has in
     * the category's paths (`['Api' => app_path('Http/Requests/Api')]`), or
     * '' when that key is a list index. When several directories hold the
     * file, the innermost one decides.
     *
     * @throws InvalidArgumentException when the key is not a valid identifier
     */
    public static function prefix(string $category, string $file): string
    {
        $prefix = '';
        $depth = -1;
        foreach ((array) config(sprintf('typefinder.%s.paths', $category), []) as $key => $dir) {
            $dir = self::realPath((string) $dir);
            if (! self::contains($dir, $file) || strlen($dir) <= $depth) {
                continue;
            }

            $depth = strlen($dir);
            $prefix = is_string($key) ? $key : '';
        }

        if ($prefix !== '' && preg_match('/^[A-Za-z_$][\w$]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'typefinder.%s.paths: the key "%s" is a type-name prefix and must be a valid TypeScript identifier.',
                $category,
                $prefix,
            ));
        }

        return $prefix;
    }

    /**
     * The directories of every enabled category.
     *
     * @return array<string, list<string>>
     */
    public static function roots(): array
    {
        $roots = [];
        foreach (self::ALL as $category) {
            if (self::enabled($category)) {
                $roots[$category] = self::paths($category);
            }
        }

        return $roots;
    }

    /**
     * Every category, enabled or not.
     *
     * @return array<string, array{enabled: bool, paths: list<string>}>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::ALL as $category) {
            $all[$category] = ['enabled' => self::enabled($category), 'paths' => self::paths($category)];
        }

        return $all;
    }

    /**
     * Whether a file is under a directory. Separators are compared alike, as
     * Windows paths may use either (#183).
     */
    public static function contains(string $dir, string $file): bool
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        return str_starts_with(str_replace('\\', '/', $file), $dir.'/');
    }

    /**
     * The real path, so a file or directory compares equal however it was
     * named. A deleted file has none; its directory's is used instead.
     */
    public static function realPath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        $dir = realpath(dirname($path));

        return $dir === false ? $path : $dir.DIRECTORY_SEPARATOR.basename($path);
    }
}
