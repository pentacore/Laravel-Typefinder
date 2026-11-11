<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Finds the classes a category reads: the PHP files under a directory and
 * the class each one declares. Every extractor and the generator list files
 * through here, so they agree on which files exist and in which order.
 */
final class ClassDiscovery
{
    /**
     * The PHP files under a directory, by name.
     *
     * @return list<string> real paths; empty when the directory does not exist
     */
    public static function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (Finder::create()->files()->name('*.php')->in($dir)->sortByName() as $file) {
            $files[] = (string) $file->getRealPath();
        }

        return $files;
    }

    /**
     * The class or enum a file declares, when it can be loaded. An interface,
     * a trait and a file that declares nothing give null.
     *
     * @param  null|callable(string, RuntimeException): void  $onWarn  Told when the declared name cannot be autoloaded.
     * @return ?class-string
     */
    public static function loadable(string $file, ?callable $onWarn = null): ?string
    {
        $className = ClassNameResolver::fromFile($file);
        if ($className === null) {
            return null;
        }

        if (class_exists($className)) {
            return $className;
        }

        $failure = ClassNameResolver::autoloadFailure($className, $file);
        if ($failure instanceof RuntimeException && $onWarn !== null) {
            $onWarn($className, $failure);
        }

        return null;
    }

    /**
     * The loadable classes and enums declared under a directory, in file order.
     *
     * @param  null|callable(string, RuntimeException): void  $onWarn  Told about each declared name that cannot be autoloaded.
     * @return list<class-string>
     */
    public static function in(string $dir, ?callable $onWarn = null): array
    {
        $classes = [];
        foreach (self::files($dir) as $file) {
            $className = self::loadable($file, $onWarn);
            if ($className !== null) {
                $classes[] = $className;
            }
        }

        return $classes;
    }
}
