<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Closure;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;
use Throwable;

/**
 * The rules {@see ResourceDrift} and {@see PageDrift} share: the keys a
 * declaration promises against the literal keys code produces, key names
 * only.
 */
final readonly class DriftComparer
{
    /**
     * The shared rules. Undeclared literal keys (in order of first
     * appearance) come first, then one finding per required declared key (in
     * declaration order):
     *  - absent from every return, all closed → `never`;
     *  - absent from some return, all closed → `notAlways`;
     *  - otherwise, conditional in every return that has it, and none of
     *    those returns open → `conditional`.
     * A key conditional in one return and unconditional in another is not
     * reported: some path returns it unconditionally, so the code alone does
     * not prove the declaration wrong. Nor is a conditional key in an open
     * return: the open part may supply it unconditionally. `$shared` keys are
     * supplied from elsewhere, so they are never `never` or `notAlways`.
     *
     * @param  list<array{key: string, optional: bool}>  $declared
     * @param  list<ReturnedArray>  $returns
     * @param  array{undeclared: Closure(string): string, never: Closure(string): string, notAlways: Closure(string): string, conditional: Closure(string): string}  $messages
     * @param  list<string>  $shared
     * @return list<string>
     */
    public function compare(array $declared, bool $declaredOpen, array $returns, array $messages, array $shared = []): array
    {
        if ($returns === []) {
            return [];
        }

        $declaredKeys = array_column($declared, 'key');
        $allClosed = array_all($returns, static fn (ReturnedArray $returned): bool => ! $returned->isOpen());

        /** @var array<string, list<bool>> $seen key => per return that has it: conditional, with no open part to override it */
        $seen = [];

        foreach ($returns as $returned) {
            $open = $returned->isOpen();

            foreach ($returned->keys() as ['key' => $key, 'conditional' => $conditional]) {
                $seen[$key][] = $conditional && ! $open;
            }
        }

        $warnings = [];

        if (! $declaredOpen) {
            foreach (array_keys($seen) as $key) {
                if (! in_array((string) $key, $declaredKeys, true)) {
                    $warnings[] = $messages['undeclared']((string) $key);
                }
            }
        }

        foreach ($declared as ['key' => $key, 'optional' => $optional]) {
            if ($optional) {
                continue;
            }

            $flags = $seen[$key] ?? [];
            $isShared = in_array($key, $shared, true);

            if ($flags === []) {
                if ($allClosed && ! $isShared) {
                    $warnings[] = $messages['never']($key);
                }

                continue;
            }

            if ($allClosed && ! $isShared && count($flags) < count($returns)) {
                $warnings[] = $messages['notAlways']($key);

                continue;
            }

            if (! in_array(false, $flags, true)) {
                $warnings[] = $messages['conditional']($key);
            }
        }

        return $warnings;
    }

    /** The parsed node of a method declared in $fqcn's own file. */
    public static function methodNode(string $fqcn, string $method): ?ClassMethod
    {
        try {
            $file = new ReflectionClass($fqcn)->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        return ParsedSource::forFile($file)?->methodNode($fqcn, $method);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    public static function unique(array $warnings): array
    {
        return array_values(array_unique($warnings));
    }
}
