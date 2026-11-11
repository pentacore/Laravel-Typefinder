<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Finds the rule keys a form request tags as query-string parameters: a
 * `@query` PHPDoc tag on the array item, as dedoc/scramble reads it.
 *
 *     'scope' => ['sometimes', 'string'], // preceded by a `@query` docblock
 *
 * Reads the AST of the `rules()` method the request ends up with (its own, an
 * inherited one or one from a trait) and of the methods that one takes rules
 * from:
 *  - `parent::m()`, `$this->m()`, `self::m()` and `static::m()`;
 *  - the `rules()` of another class: `new X()->rules()`,
 *    `X::createFrom($this)->rules()` (any static factory of X),
 *    `app(X::class)->rules()` and `resolve(X::class)->rules()`.
 *
 * Never evaluates anything.
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
        $seen = [];

        self::collect($request, 'rules', $found, $seen);

        $found['keys'] = array_values(array_unique($found['keys']));

        return $found;
    }

    /**
     * Read the tags in one method, then in the methods it takes rules from.
     *
     * @param  ReflectionClass<object>  $class  the class the method is called on
     * @param  array{keys: list<string>, unreadable: int}  $found
     * @param  array<string, true>  $seen  methods already read, so recursion ends
     */
    private static function collect(ReflectionClass $class, string $methodName, array &$found, array &$seen): void
    {
        if (! $class->hasMethod($methodName)) {
            return;
        }

        $method = $class->getMethod($methodName);
        $owner = $method->getDeclaringClass();
        $id = $owner->getName().'::'.strtolower($methodName);

        // The framework's own methods (`$this->user()`, `$this->route()`) hold no rules.
        if (isset($seen[$id]) || is_a(FormRequest::class, $owner->getName(), true)) {
            return;
        }

        $seen[$id] = true;
        $stmts = self::methodNode($method)->stmts ?? [];
        $nodeFinder = new NodeFinder;

        foreach ($nodeFinder->findInstanceOf($stmts, ArrayItem::class) as $item) {
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

        $calls = $nodeFinder->find($stmts, fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall);

        foreach ($calls as $call) {
            $target = $call->name instanceof Identifier ? self::calledClass($call, $class, $owner) : null;

            if ($target instanceof ReflectionClass) {
                self::collect($target, $call->name->toString(), $found, $seen);
            }
        }
    }

    /**
     * The class a call inside a rules method takes more rules from; null for
     * any other call.
     *
     * @param  ReflectionClass<object>  $class  the class the calling method was called on (`$this`, `static`)
     * @param  ReflectionClass<object>  $owner  the class that declares the calling method (`self`, `parent`)
     * @return ?ReflectionClass<object>
     */
    private static function calledClass(MethodCall|StaticCall $call, ReflectionClass $class, ReflectionClass $owner): ?ReflectionClass
    {
        if ($call instanceof StaticCall) {
            return match ($call->class instanceof Name ? $call->class->toLowerString() : null) {
                'parent' => $owner->getParentClass() ?: null,
                'self' => $owner,
                'static' => $class,
                default => null,
            };
        }

        if ($call->var instanceof Variable && $call->var->name === 'this') {
            return $class;
        }

        if ($call->name->toString() !== 'rules') {
            return null;
        }

        $receiver = $call->var;
        $name = match (true) {
            // new X()->rules() and X::createFrom($this)->rules()
            $receiver instanceof New_, $receiver instanceof StaticCall => $receiver->class,
            // app(X::class)->rules() and resolve(X::class)->rules()
            $receiver instanceof FuncCall => self::resolvedClassName($receiver),
            default => null,
        };

        if (! $name instanceof Name || $name->isSpecialClassName() || ! class_exists($name->toString())) {
            return null;
        }

        return new ReflectionClass($name->toString());
    }

    /**
     * The `X` of `app(X::class)` / `resolve(X::class)`.
     */
    private static function resolvedClassName(FuncCall $call): ?Node
    {
        $argument = $call->args[0] ?? null;

        if (! $call->name instanceof Name
            || ! in_array($call->name->toLowerString(), ['app', 'resolve'], true)
            || ! $argument instanceof Arg
            || ! $argument->value instanceof ClassConstFetch
            || ! $argument->value->name instanceof Identifier
            || $argument->value->name->toLowerString() !== 'class') {
            return null;
        }

        return $argument->value->class;
    }

    /**
     * The AST of a method, read from the file that declares it.
     */
    private static function methodNode(ReflectionMethod $method): ?ClassMethod
    {
        $file = $method->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);

        if (! $source instanceof ParsedSource) {
            return null;
        }

        // A trait's method reports the using class as its declaring class.
        $owner = $method->getDeclaringClass();
        $node = null;

        foreach ([$owner->getName(), ...$owner->getTraitNames()] as $candidate) {
            $node ??= $source->methodNode($candidate, $method->getName());
        }

        return $node;
    }
}
