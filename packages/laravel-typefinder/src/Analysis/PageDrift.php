<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Drift between a `#[TypefinderPage]` and the props its own action passes to
 * `Inertia::render()` / `inertia()`. Precision over recall, as
 * {@see DriftComparer::compare()}.
 */
final readonly class PageDrift
{
    private const string INERTIA = 'Inertia\\Inertia';

    private DriftComparer $comparer;

    /**
     * @param  list<string>  $sharedPageProps  Props every page gets; never reported as not passed.
     */
    public function __construct(private ReturnedArrayReader $reader, private array $sharedPageProps)
    {
        $this->comparer = new DriftComparer;
    }

    /**
     * {@see self::checkPage()} for each action and component, after merging
     * the `#[TypefinderPage]` entries one action declares for one component
     * (the attribute is repeatable) key by key, as the generated type does:
     * every declared prop counts, and a prop is optional when any entry
     * lacks it or lists it as optional. Types are not compared.
     *
     * @param  list<array{component: string, props: array<array-key, string>, optional: list<string>, source: string}>  $pages
     * @return list<string>
     */
    public function checkPages(array $pages): array
    {
        $groups = [];

        foreach ($pages as $page) {
            $groups[$page['source']."\0".$page['component']][] = $page;
        }

        $warnings = [];

        foreach ($groups as $entries) {
            $warnings = [...$warnings, ...$this->checkPage($this->mergedPage($entries))];
        }

        return DriftComparer::unique($warnings);
    }

    /**
     * Drift between a `#[TypefinderPage]` and the props its own action
     * passes to `Inertia::render('X', …)` / `inertia('X', …)` for the same
     * component (each matching call, with any chained `->with()`, is one
     * set of props). An action that renders elsewhere (a helper, a base
     * controller) has no matching call and reports nothing. Shared props
     * (`errors`, the app's shared props) are never reported as not passed,
     * and an action that calls `Inertia::share()` itself may pass any prop.
     *
     * @param  array{component: string, props: array<array-key, string>, optional: list<string>, source: string}  $page
     * @return list<string>
     */
    public function checkPage(array $page): array
    {
        [$class, $method] = explode('::', $page['source'], 2) + [1 => ''];
        $node = DriftComparer::methodNode(ltrim($class, '\\'), $method);

        if (! $node instanceof ClassMethod) {
            return [];
        }

        $component = $page['component'];
        $optional = array_map(strval(...), $page['optional']);
        $declared = array_map(
            static fn (int|string $key): array => ['key' => (string) $key, 'optional' => in_array((string) $key, $optional, true)],
            array_keys($page['props']),
        );

        $renders = $this->renders($node, $component);

        $share = $this->shareCall($node);

        if ($share instanceof Expr) {
            // Whatever it shares joins every render of this request.
            $renders = array_map(
                fn (ReturnedArray $props): ReturnedArray => new ReturnedArray([...$props->parts, $this->open($share)]),
                $renders,
            );
        }

        return DriftComparer::unique($this->comparer->compare($declared, false, $renders, [
            'undeclared' => static fn (string $key): string => sprintf('%s::%s() passes prop "%s" to Inertia page "%s" but #[TypefinderPage] does not declare it', $class, $method, $key, $component),
            'never' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" but it is never passed', $class, $method, $key, $component),
            'notAlways' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" as required but it is not always passed (add it to optional?)', $class, $method, $key, $component),
            'conditional' => static fn (string $key): string => sprintf('%s::%s(): prop "%s" of "%s" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)', $class, $method, $key, $component),
        ], $this->sharedPageProps));
    }

    /**
     * One action's entries for one component as a single entry, merged key
     * by key like {@see PageMerger::merge()}, which also fails on type
     * conflicts and so is not reused here.
     *
     * @param  non-empty-list<array{component: string, props: array<array-key, string>, optional: list<string>, source: string}>  $entries
     * @return array{component: string, props: array<array-key, string>, optional: list<string>, source: string}
     */
    private function mergedPage(array $entries): array
    {
        if (count($entries) === 1) {
            return $entries[0];
        }

        $props = [];
        $declaredBy = [];
        $optional = [];

        foreach ($entries as $entry) {
            $entryOptional = array_map(strval(...), $entry['optional']);

            foreach ($entry['props'] as $key => $type) {
                $props[$key] ??= $type;
                $declaredBy[$key] = ($declaredBy[$key] ?? 0) + 1;

                if (in_array((string) $key, $entryOptional, true)) {
                    $optional[$key] = true;
                }
            }
        }

        foreach ($declaredBy as $key => $count) {
            if ($count < count($entries)) {
                $optional[$key] = true;
            }
        }

        return [
            'component' => $entries[0]['component'],
            'props' => $props,
            'optional' => array_map(strval(...), array_keys($optional)),
            'source' => $entries[0]['source'],
        ];
    }

    /**
     * The first `Inertia::share(…)` or `inertia()->share(…)` call anywhere in
     * the method body, or null.
     */
    private function shareCall(ClassMethod $method): ?Expr
    {
        $found = new NodeFinder()->findFirst($method->stmts ?? [], static function (Node $node): bool {
            if ($node instanceof StaticCall) {
                return $node->class instanceof Name
                    && $node->name instanceof Identifier
                    && $node->name->toLowerString() === 'share'
                    && ReturnedArrayReader::refersTo($node->class, self::INERTIA);
            }

            return $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->toLowerString() === 'share'
                && $node->var instanceof FuncCall
                && $node->var->name instanceof Name
                && strcasecmp($node->var->name->toString(), 'inertia') === 0
                && $node->var->args === [];
        });

        return $found instanceof Expr ? $found : null;
    }

    /**
     * The props of each render call for $component in the method body, in
     * source order. Calls inside nested closures, arrow functions, functions
     * and anonymous classes are skipped. Only a render (with its chained
     * `->with()` calls) that is returned directly, or as an arm of a ternary
     * or `match` that is returned, has exactly the props read; anywhere else
     * (assigned, passed on, receiver of another method) more props may be
     * added later, so its props are open. When any of the method's own
     * returns is something else (a helper or base-controller call, a
     * redirect, another page, a bare `return;`), that path may render the
     * component elsewhere with other props, so every render is open.
     *
     * @return list<ReturnedArray>
     */
    private function renders(ClassMethod $method, string $component): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $visitor = new class($this->renderProps(...), $component) extends NodeVisitorAbstract
        {
            /** @var list<ReturnedArray> */
            public array $renders = [];

            /** @var array<int, true> spl_object_id of expressions returned as they are */
            private array $returned = [];

            /** @var array<int, true> spl_object_id of the matched render expressions */
            private array $matched = [];

            private bool $bareReturn = false;

            /** @param  Closure(Expr, string): ?ReturnedArray  $match */
            public function __construct(private readonly Closure $match, private readonly string $component) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClosureExpr || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_) {
                    if ($node->expr instanceof Expr) {
                        $this->markReturned($node->expr);
                    } else {
                        $this->bareReturn = true;
                    }
                }

                $props = $node instanceof Expr ? ($this->match)($node, $this->component) : null;

                if (! $props instanceof ReturnedArray) {
                    return null;
                }

                $this->matched[spl_object_id($node)] = true;
                $this->renders[] = isset($this->returned[spl_object_id($node)])
                    ? $props
                    : new ReturnedArray([...$props->parts, new ReturnedPart(ReturnedPart::EXPR, expr: $node)]);

                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            /** Some return of the method is not a matched render. */
            public function hasOtherReturn(): bool
            {
                return $this->bareReturn || array_diff_key($this->returned, $this->matched) !== [];
            }

            private function markReturned(Expr $expr): void
            {
                if ($expr instanceof Ternary) {
                    $this->markReturned($expr->if ?? $expr->cond);
                    $this->markReturned($expr->else);

                    return;
                }

                if ($expr instanceof Match_) {
                    foreach ($expr->arms as $arm) {
                        $this->markReturned($arm->body);
                    }

                    return;
                }

                $this->returned[spl_object_id($expr)] = true;
            }
        };

        new NodeTraverser($visitor)->traverse($method->stmts);

        if (! $visitor->hasOtherReturn()) {
            return $visitor->renders;
        }

        // An opaque part with no expression: whatever the other path renders.
        return array_map(
            static fn (ReturnedArray $props): ReturnedArray => new ReturnedArray([...$props->parts, new ReturnedPart(ReturnedPart::EXPR)]),
            $visitor->renders,
        );
    }

    /**
     * The props of `<render call for $component>->with(…)->with(…)`, or null
     * when $expr is not such a call.
     */
    private function renderProps(Expr $expr, string $component): ?ReturnedArray
    {
        $render = $this->render($expr);

        return $render !== null && $render['component'] === $component ? $render['props'] : null;
    }

    /**
     * The literal component and the props of `<render call>->with(…)->with(…)`,
     * or null when $expr is not such a call or its component is not a
     * string literal.
     *
     * @return ?array{component: string, props: ReturnedArray}
     */
    public function render(Expr $expr): ?array
    {
        $withs = [];

        while ($expr instanceof MethodCall && $expr->name instanceof Identifier && $expr->name->toLowerString() === 'with') {
            $withs[] = $expr;
            $expr = $expr->var;
        }

        if (! $this->isRenderCall($expr)) {
            return null;
        }

        $first = $this->argument($expr, 0, 'component');

        if (! $first instanceof String_) {
            return null;
        }

        $parts = $this->propsParts($expr);

        foreach (array_reverse($withs) as $with) {
            $parts = [...$parts, ...$this->withParts($with)];
        }

        return ['component' => $first->value, 'props' => new ReturnedArray($parts)];
    }

    /** @phpstan-assert-if-true StaticCall|FuncCall $expr */
    private function isRenderCall(Expr $expr): bool
    {
        if ($expr instanceof StaticCall) {
            return $expr->class instanceof Name
                && $expr->name instanceof Identifier
                && $expr->name->toLowerString() === 'render'
                && ! $expr->isFirstClassCallable()
                && ReturnedArrayReader::refersTo($expr->class, self::INERTIA);
        }

        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && ! $expr->isFirstClassCallable()
            && strcasecmp($expr->name->toString(), 'inertia') === 0;
    }

    /**
     * The `$props` argument: a literal array, `compact()` of literal names,
     * none (no props), or anything else (open).
     *
     * @return list<ReturnedPart>
     */
    private function propsParts(StaticCall|FuncCall $render): array
    {
        if ($this->hasUnpackedArgument($render)) {
            return [$this->open($render)];
        }

        $props = $this->argument($render, 1, 'props');

        if (! $props instanceof Expr) {
            return [];
        }

        if ($props instanceof Array_) {
            return $this->reader->read($props)->parts;
        }

        if ($props instanceof FuncCall && $props->name instanceof Name && ! $props->isFirstClassCallable() && $props->name->toLowerString() === 'compact') {
            $parts = [];

            foreach ($props->args as $arg) {
                if (! $arg instanceof Arg || $arg->unpack || ! $arg->value instanceof String_) {
                    return [$this->open($props)];
                }

                // The value is the variable compact() reads, keeping the name's attributes (its position, and the scope Rector rules type it in).
                $parts[] = new ReturnedPart(ReturnedPart::ITEM, key: $arg->value->value, value: new Variable($arg->value->value, $arg->value->getAttributes()));
            }

            return $parts;
        }

        return [$this->open($props)];
    }

    /**
     * `->with([...])` adds the array's items, `->with('k', $v)` adds `k`;
     * anything else is open.
     *
     * @return list<ReturnedPart>
     */
    private function withParts(MethodCall $with): array
    {
        if ($this->hasUnpackedArgument($with)) {
            return [$this->open($with)];
        }

        $key = $this->argument($with, 0, 'key');

        if ($key instanceof Array_) {
            return $this->reader->read($key)->parts;
        }

        if ($key instanceof String_) {
            $value = $this->argument($with, 1, 'value') ?? new ConstFetch(new Name('null'));

            return $this->reader->read(new Array_([new ArrayItem($value, $key)]))->parts;
        }

        return [$this->open($with)];
    }

    private function open(Expr $expr): ReturnedPart
    {
        return new ReturnedPart(ReturnedPart::EXPR, expr: $expr);
    }

    /** A spread argument or a first-class-callable placeholder. */
    private function hasUnpackedArgument(CallLike $call): bool
    {
        return array_any($call->args, static fn (Arg|VariadicPlaceholder $arg): bool => ! $arg instanceof Arg || $arg->unpack);
    }

    /** The argument at a zero-based position, or passed by name. */
    private function argument(CallLike $call, int $position, string $name): ?Expr
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
}
