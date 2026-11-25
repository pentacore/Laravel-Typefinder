<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionMethod;

/**
 * Finds the rule keys a form request tags as query-string parameters: a
 * `@query` PHPDoc tag on the array item, as dedoc/scramble reads it.
 *
 *     'scope' => ['sometimes', 'string'], // preceded by a `@query` docblock
 *
 * A tag on a spread item (`...$this->targetRules()`) tags every key the
 * spread brings in, for this request only: the keys of the array literals
 * the spread method returns (following nested spreads, `array_merge()` and
 * `return $this->other()`).
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
     *                                                    or a spread whose keys can be read
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

            $keys = match (true) {
                $item->key instanceof String_ => [$item->key->value],
                $item->unpack => self::keysOf($item->value, $class, $owner, []),
                default => null,
            };

            if ($keys === null) {
                $found['unreadable']++;
            } else {
                array_push($found['keys'], ...$keys);
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
     * The rule keys an expression evaluates to, read from the source; null
     * when one of them cannot be read.
     *
     * @param  ReflectionClass<object>  $class  see calledClass()
     * @param  ReflectionClass<object>  $owner  see calledClass()
     * @param  array<string, true>  $seen  methods already being read, so recursion ends
     * @return ?list<string>
     */
    private static function keysOf(Expr $expr, ReflectionClass $class, ReflectionClass $owner, array $seen): ?array
    {
        if ($expr instanceof Array_) {
            $keys = [];

            foreach ($expr->items as $item) {
                $itemKeys = match (true) {
                    $item->key instanceof String_ => [$item->key->value],
                    $item->unpack => self::keysOf($item->value, $class, $owner, $seen),
                    default => null,
                };

                if ($itemKeys === null) {
                    return null;
                }

                array_push($keys, ...$itemKeys);
            }

            return $keys;
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'array_merge') {
            return self::keysOfAll(array_map(
                static fn (Node $argument): ?Expr => $argument instanceof Arg && ! $argument->unpack ? $argument->value : null,
                $expr->args,
            ), $class, $owner, $seen);
        }

        if (! $expr instanceof MethodCall && ! $expr instanceof StaticCall || ! $expr->name instanceof Identifier) {
            return null;
        }

        $target = self::calledClass($expr, $class, $owner);
        $methodName = $expr->name->toString();

        if (! $target instanceof ReflectionClass || ! $target->hasMethod($methodName)) {
            return null;
        }

        $method = $target->getMethod($methodName);
        $id = $method->getDeclaringClass()->getName().'::'.strtolower($methodName);
        $stmts = self::methodNode($method)->stmts ?? null;

        if (isset($seen[$id]) || $stmts === null) {
            return null;
        }

        $seen[$id] = true;

        return self::keysOfAll(self::returnedExpressions($stmts), $target, $method->getDeclaringClass(), $seen);
    }

    /**
     * The keys of every expression, or null when one cannot be read (or
     * there are none).
     *
     * @param  list<?Expr>  $exprs
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $owner
     * @param  array<string, true>  $seen
     * @return ?list<string>
     */
    private static function keysOfAll(array $exprs, ReflectionClass $class, ReflectionClass $owner, array $seen): ?array
    {
        if ($exprs === []) {
            return null;
        }

        $keys = [];

        foreach ($exprs as $expr) {
            $exprKeys = $expr instanceof Expr ? self::keysOf($expr, $class, $owner, $seen) : null;

            if ($exprKeys === null) {
                return null;
            }

            array_push($keys, ...$exprKeys);
        }

        return $keys;
    }

    /**
     * What the `return` statements of a method body return; a closure or
     * class declared in the body returns for itself, so it is skipped.
     *
     * @param  array<Node>  $stmts
     * @return list<?Expr>
     */
    private static function returnedExpressions(array $stmts): array
    {
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<?Expr> */
            public array $returned = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Return_) {
                    $this->returned[] = $node->expr;
                }

                return $node instanceof FunctionLike || $node instanceof ClassLike ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }
        };

        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($stmts);

        return $visitor->returned;
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
    public static function methodNode(ReflectionMethod $method): ?ClassMethod
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
