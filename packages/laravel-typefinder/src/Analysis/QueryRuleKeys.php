<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Finds the rule keys a form request tags as query-string parameters: a
 * `@query` PHPDoc tag on the array item, as dedoc/scramble reads it.
 *
 *     'scope' => ['sometimes', 'string'], // preceded by a `@query` docblock
 *
 * Reads the AST of the `rules()` method the request ends up with (its own, an
 * inherited one or one from a trait); never evaluates anything.
 */
final class QueryRuleKeys
{
    /**
     * @param  ReflectionClass<object>  $request
     * @return array{keys: list<string>, unreadable: int} the tagged keys, and
     *                                                    how many tags sit on a key that is not a string literal
     */
    public static function of(ReflectionClass $request): array
    {
        $found = ['keys' => [], 'unreadable' => 0];

        if (! $request->hasMethod('rules')) {
            return $found;
        }

        $method = $request->getMethod('rules');
        $file = $method->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);

        if (! $source instanceof ParsedSource) {
            return $found;
        }

        // A trait's method reports the using class as its declaring class.
        $owner = $method->getDeclaringClass();
        $node = null;

        foreach ([$owner->getName(), ...$owner->getTraitNames()] as $candidate) {
            $node = $source->methodNode($candidate, 'rules');

            if ($node instanceof ClassMethod) {
                break;
            }
        }

        foreach (new NodeFinder()->findInstanceOf($node->stmts ?? [], ArrayItem::class) as $item) {
            $doc = ($item->getDocComment() ?? $item->key?->getDocComment())?->getText();

            if ($doc === null || preg_match('/@query\b/', $doc) !== 1) {
                continue;
            }

            if ($item->key instanceof String_) {
                $found['keys'][] = $item->key->value;
            } else {
                $found['unreadable']++;
            }
        }

        return $found;
    }
}
