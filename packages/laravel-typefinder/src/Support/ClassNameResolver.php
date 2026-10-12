<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use PhpToken;
use RuntimeException;

/**
 * Resolves the fully-qualified name of the first class-like declaration
 * (class, enum, interface, trait) in a PHP file.
 *
 * Works on tokens rather than a regex so that words in comments, docblocks
 * and strings ("backed enum as well…", "// see namespace Foo;") are never
 * mistaken for the declaration. `Foo::class` constants and anonymous
 * `new class {}` expressions are skipped too.
 */
final class ClassNameResolver
{
    private const array DECLARATIONS = [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT];

    public static function fromFile(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);

        return $contents === false ? null : self::fromSource($contents);
    }

    public static function fromSource(string $source): ?string
    {
        $tokens = PhpToken::tokenize($source);
        $namespace = '';

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $next = self::nextSignificant($tokens, $i);
                // `namespace {` opens the global namespace.
                $namespace = $next instanceof PhpToken && $next->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text : '';

                continue;
            }

            if (! $token->is(self::DECLARATIONS)) {
                continue;
            }

            // `Foo::class` is a constant, not a declaration.
            if (self::previousSignificant($tokens, $i)?->is(T_DOUBLE_COLON)) {
                continue;
            }

            // Anonymous classes (`new class {}`) have no name token.
            $name = self::nextSignificant($tokens, $i);
            if (! $name instanceof PhpToken || ! $name->is(T_STRING)) {
                continue;
            }

            return $namespace === '' ? $name->text : $namespace.'\\'.$name->text;
        }

        return null;
    }

    /**
     * Explain why a resolved name is unusable when nothing by that name can be
     * autoloaded — almost always a namespace that doesn't match the file's
     * PSR-4 path. Returns null when the name exists (as a class, enum,
     * interface or trait), so callers can still skip non-matching kinds
     * silently.
     */
    public static function autoloadFailure(string $className, string $filePath): ?RuntimeException
    {
        if (class_exists($className) || interface_exists($className) || trait_exists($className)) {
            return null;
        }

        return new RuntimeException(sprintf(
            'declared in %s but could not be autoloaded — check that its namespace matches the autoload path',
            $filePath,
        ));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?PhpToken
    {
        for ($j = $from + 1, $n = count($tokens); $j < $n; $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function previousSignificant(array $tokens, int $from): ?PhpToken
    {
        for ($j = $from - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
