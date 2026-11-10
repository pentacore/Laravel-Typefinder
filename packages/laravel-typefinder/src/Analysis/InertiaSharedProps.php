<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Pentacore\Typefinder\Support\ClassNameResolver;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The prop keys an app shares with every Inertia page: the literal keys any
 * return of `share()` produces in classes extending `Inertia\Middleware`
 * (`array_merge(parent::share($request), [...])`, `[...parent::share($request),
 * ...]`). Source is only parsed, never loaded, so Inertia need not be
 * installed. Unreadable or unparsable files contribute nothing.
 */
final class InertiaSharedProps
{
    private const string MIDDLEWARE = 'Inertia\\Middleware';

    /**
     * @return list<string> keys in order of first appearance
     */
    public static function fromDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $reader = new ReturnedArrayReader;
        $keys = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            try {
                foreach (self::shareReturns($file->getRealPath() ?: $file->getPathname(), $reader) as $returned) {
                    foreach ($returned->keys() as ['key' => $key]) {
                        $keys[$key] = true;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return array_map(strval(...), array_keys($keys));
    }

    /** @return list<ReturnedArray> */
    private static function shareReturns(string $path, ReturnedArrayReader $reader): array
    {
        $class = ClassNameResolver::fromFile($path);
        $source = $class === null ? null : ParsedSource::forFile($path);

        if ($class === null || ! $source instanceof ParsedSource || strcasecmp((string) $source->parentName($class), self::MIDDLEWARE) !== 0) {
            return [];
        }

        $method = $source->methodNode($class, 'share');

        return $method instanceof ClassMethod ? $reader->returns($method) : [];
    }
}
