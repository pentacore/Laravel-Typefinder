<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

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

    /** Off unless the config turns them on: they need Inertia or broadcasting. */
    private const array OPT_IN = ['inertia', 'broadcasting'];

    public static function enabled(string $category): bool
    {
        return (bool) config(sprintf('typefinder.%s.enabled', $category), ! in_array($category, self::OPT_IN, true));
    }

    /**
     * The directories a category reads.
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

        return $dir === false ? $path : $dir.'/'.basename($path);
    }
}
