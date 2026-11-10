<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Decomposes the arrays a method returns (a resource's `toArray()`, the props
 * passed to an Inertia page) into ordered {@see ReturnedPart}s: literal-key
 * items, dynamic items and opaque sub-expressions. Reads the AST produced by
 * {@see ParsedSource}; never evaluates anything.
 *
 * A value is conditional when it is:
 *  - `$this->when*(…)`, `unless()` or `transform()` from the closed list in
 *    {@see self::WHEN_DEFAULT_POSITION} called without its `$default`
 *    argument (positional or named `default:`);
 *  - a static call matching one of the `$conditionalWrappers`;
 *  - `X::collection(<conditional>)`, `X::make(<conditional>)` or
 *    `new X(<conditional>)` where X is an existing `JsonResource` subclass.
 *
 * `$this->merge([...])` items are inlined as they are; `$this->mergeWhen($c,
 * [...])` and `mergeUnless()` items are inlined as conditional (with a
 * literal default, only the keys missing from the other branch are
 * conditional). `$this->attributes([...])` is a dynamic part.
 */
final readonly class ReturnedArrayReader
{
    /**
     * Zero-based position of the `$default` parameter of each conditional
     * value helper of `ConditionallyLoadsAttributes` (lower-case names).
     */
    private const array WHEN_DEFAULT_POSITION = [
        'when' => 2,
        'whenloaded' => 2,
        'whencounted' => 2,
        'whenhas' => 2,
        'whennotnull' => 1,
        'whenaggregated' => 4,
        'whenpivotloaded' => 2,
        'whenpivotloadedas' => 3,
        'whenexistsloaded' => 2,
        'unless' => 2,
        'whennull' => 1,
        'whenappended' => 2,
        'transform' => 2,
    ];

    /** Position of `$default` in `mergeWhen()`/`mergeUnless($condition, $value, $default)`. */
    private const int MERGE_WHEN_DEFAULT_POSITION = 2;

    /** @var array<string, list<string>> lower-case method name => wrapper class FQCNs */
    private array $wrappers;

    /**
     * @param  list<string>  $conditionalWrappers  `FQCN::method` names whose
     *                                             static call marks the wrapped value conditional, e.g.
     *                                             `Inertia\Inertia::optional`. Classes are matched with
     *                                             {@see self::refersTo()}, methods case-insensitively.
     */
    public function __construct(array $conditionalWrappers = [])
    {
        $wrappers = [];

        foreach ($conditionalWrappers as $wrapper) {
            [$class, $method] = explode('::', $wrapper, 2) + [1 => ''];
            $wrappers[strtolower($method)][] = ltrim($class, '\\');
        }

        $this->wrappers = $wrappers;
    }

    /**
     * Whether a resolved class name refers to $fqcn: the resolved name equals
     * it, or is $fqcn's global alias (its short name in the global namespace:
     * `\Inertia`, `use Inertia;`, or `Inertia` in a file without namespace).
     * An unqualified, unimported name inside a namespace resolves to that
     * namespace (PHP has no global fallback for classes) and does not match.
     * Case-insensitive.
     */
    public static function refersTo(Name $name, string $fqcn): bool
    {
        $fqcn = ltrim($fqcn, '\\');

        if (strcasecmp($name->toString(), $fqcn) === 0) {
            return true;
        }

        $separator = strrpos($fqcn, '\\');
        $alias = $separator === false ? $fqcn : substr($fqcn, $separator + 1);

        return strcasecmp($name->toString(), $alias) === 0;
    }

    /**
     * One entry per `return <expr>;` in the method body, in source order.
     * Returns inside nested closures, arrow functions, functions and
     * anonymous classes are not the method's own and are skipped.
     *
     * @return list<ReturnedArray>
     */
    public function returns(ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $collector = new class extends NodeVisitorAbstract
        {
            /** @var list<Expr> */
            public array $returned = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_ && $node->expr instanceof Expr) {
                    $this->returned[] = $node->expr;
                }

                return null;
            }
        };

        new NodeTraverser($collector)->traverse($method->stmts);

        return array_map($this->read(...), $collector->returned);
    }

    /**
     * Decompose a single expression. Literal arrays, `array_merge()` and `+`
     * are taken apart; anything else is one `expr` part (`combine: whole`).
     */
    public function read(Expr $expr): ReturnedArray
    {
        return new ReturnedArray($this->decompose($expr, 'whole'));
    }

    /**
     * @param  string  $combine  how $expr joins the array when it is opaque
     * @return list<ReturnedPart>
     */
    private function decompose(Expr $expr, string $combine): array
    {
        if ($expr instanceof Array_) {
            return $this->arrayItems($expr, false);
        }

        if ($expr instanceof Plus) {
            return [...$this->decompose($expr->left, 'plus'), ...$this->decompose($expr->right, 'plus')];
        }

        if ($this->isArrayMerge($expr)) {
            $parts = [];

            foreach ($expr->getArgs() as $arg) {
                $parts = [...$parts, ...($arg->unpack ? [$this->opaque($arg->value, 'merge')] : $this->decompose($arg->value, 'merge'))];
            }

            return $parts;
        }

        return [$this->opaque($expr, $combine)];
    }

    /**
     * @param  bool  $conditional  force every item conditional (`mergeWhen`)
     * @return list<ReturnedPart>
     */
    private function arrayItems(Array_ $array, bool $conditional): array
    {
        $parts = [];

        foreach ($array->items as $item) {
            $parts = [...$parts, ...$this->arrayItem($item, $conditional)];
        }

        return $parts;
    }

    /** @return list<ReturnedPart> */
    private function arrayItem(ArrayItem $item, bool $conditional): array
    {
        $value = $item->value;

        if ($item->unpack) {
            return $value instanceof Array_
                ? $this->arrayItems($value, $conditional)
                : [$this->opaque($value, 'spread', $conditional)];
        }

        if (! $item->key instanceof Expr && $this->isThisCall($value, 'merge')) {
            return $this->mergedParts($value, $conditional);
        }

        if (! $item->key instanceof Expr && ($this->isThisCall($value, 'mergeWhen') || $this->isThisCall($value, 'mergeUnless'))) {
            return $this->mergeWhenParts($value, $conditional);
        }

        if ($item->key instanceof String_) {
            return [new ReturnedPart(
                ReturnedPart::ITEM,
                key: $item->key->value,
                value: $value,
                conditional: $conditional || $this->isConditional($value),
            )];
        }

        return [new ReturnedPart(ReturnedPart::DYNAMIC, value: $value)];
    }

    /** @return list<ReturnedPart> `$this->merge($value)` */
    private function mergedParts(MethodCall $call, bool $conditional): array
    {
        $value = $this->argument($call, 0, 'value');

        return $value instanceof Array_
            ? $this->arrayItems($value, $conditional)
            : [$this->opaque($value ?? $call, 'merge', $conditional)];
    }

    /**
     * `$this->mergeWhen($condition, $value[, $default])` (or `mergeUnless()`,
     * the same with the condition negated). Without a default
     * every part is conditional. With one, both branches are emitted: their
     * opaque `expr` parts are conditional (mutually exclusive alternatives),
     * and an item is conditional only when the other branch is a literal
     * array that lacks its key — an opaque branch might hold it, so it is
     * not claimed.
     *
     * @param  bool  $conditional  already inside a conditional merge
     * @return list<ReturnedPart>
     */
    private function mergeWhenParts(MethodCall $call, bool $conditional): array
    {
        $value = $this->argument($call, 1, 'value');
        $default = $this->argument($call, self::MERGE_WHEN_DEFAULT_POSITION, 'default');

        if (! $default instanceof Expr) {
            return $value instanceof Array_
                ? $this->arrayItems($value, true)
                : [$this->opaque($value ?? $call, 'merge', true)];
        }

        $branches = [$this->branchParts($value ?? $call), $this->branchParts($default)];
        $parts = [];

        foreach ($branches as $index => $branch) {
            $other = $branches[1 - $index];
            $otherClosed = ! new ReturnedArray($other)->isOpen();
            $otherKeys = array_column(new ReturnedArray($other)->keys(), 'key');

            foreach ($branch as $part) {
                $parts[] = $conditional
                    || $part->kind === ReturnedPart::EXPR
                    || ($part->kind === ReturnedPart::ITEM && $otherClosed && ! in_array($part->key, $otherKeys, true))
                    ? $this->asConditional($part)
                    : $part;
            }
        }

        return $parts;
    }

    /** @return list<ReturnedPart> */
    private function branchParts(Expr $branch): array
    {
        return $branch instanceof Array_ ? $this->arrayItems($branch, false) : [$this->opaque($branch, 'merge')];
    }

    private function asConditional(ReturnedPart $part): ReturnedPart
    {
        return new ReturnedPart($part->kind, $part->key, $part->value, true, $part->expr, $part->combine);
    }

    private function opaque(Expr $expr, string $combine, bool $conditional = false): ReturnedPart
    {
        return new ReturnedPart(ReturnedPart::EXPR, conditional: $conditional, expr: $expr, combine: $combine);
    }

    private function isConditional(Expr $value): bool
    {
        if ($value instanceof MethodCall && $value->name instanceof Identifier && $value->var instanceof Variable && $value->var->name === 'this') {
            $position = self::WHEN_DEFAULT_POSITION[$value->name->toLowerString()] ?? null;

            return $position !== null && $this->hasPlainArguments($value) && ! $this->argument($value, $position, 'default') instanceof Expr;
        }

        if ($value instanceof StaticCall && $value->class instanceof Name && $value->name instanceof Identifier) {
            $method = $value->name->toLowerString();

            if ($method === 'collection' || $method === 'make') {
                return $this->isJsonResource($value->class) && $this->firstArgumentIsConditional($value);
            }

            return array_any($this->wrappers[$method] ?? [], fn (string $class): bool => self::refersTo($value->class, $class));
        }

        return $value instanceof New_
            && $value->class instanceof Name
            && $this->isJsonResource($value->class)
            && $this->firstArgumentIsConditional($value);
    }

    /**
     * Only an existing resource class passes a `MissingValue` through, so
     * only then does wrapping a conditional value keep it conditional.
     */
    private function isJsonResource(Name $class): bool
    {
        $fqcn = $class->toString();

        return class_exists($fqcn) && is_subclass_of($fqcn, JsonResource::class);
    }

    private function firstArgumentIsConditional(StaticCall|New_ $call): bool
    {
        $first = $call->args[0] ?? null;

        return $first instanceof Arg && ! $first->unpack && $this->isConditional($first->value);
    }

    /** No spread arguments and no first-class-callable placeholder. */
    private function hasPlainArguments(MethodCall $call): bool
    {
        return array_all($call->args, fn (Arg|VariadicPlaceholder $arg): bool => $arg instanceof Arg && ! $arg->unpack);
    }

    /** The argument at a zero-based position, or passed by name. */
    private function argument(MethodCall $call, int $position, string $name): ?Expr
    {
        foreach ($call->args as $index => $arg) {
            if (! $arg instanceof Arg || $arg->unpack) {
                continue;
            }

            if ($arg->name instanceof Identifier ? $arg->name->toString() === $name : $index === $position) {
                return $arg->value;
            }
        }

        return null;
    }

    /** @phpstan-assert-if-true MethodCall $expr */
    private function isThisCall(Expr $expr, string $method): bool
    {
        return $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && $expr->name->toLowerString() === strtolower($method);
    }

    /** @phpstan-assert-if-true FuncCall $expr */
    private function isArrayMerge(Expr $expr): bool
    {
        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && ! $expr->isFirstClassCallable()
            && $expr->name->toLowerString() === 'array_merge';
    }
}
