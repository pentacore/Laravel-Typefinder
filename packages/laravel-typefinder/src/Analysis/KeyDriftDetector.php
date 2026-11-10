<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Closure;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
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
use ReflectionClass;
use Throwable;

/**
 * Compares the literal keys code produces with the keys a declaration
 * promises, key names only: a resource's `toArray()` against its
 * `#[TypefinderResource] shape` or PHPDoc `@return` shape, and the props an
 * action passes to `Inertia::render()` against its `#[TypefinderPage]`.
 *
 * Precision over recall: a key is reported missing only when every return is
 * closed (fully literal), an undeclared key only when it is a literal key, and
 * a key as conditional only when it is conditional everywhere it appears.
 * Unreadable or unparsable source reports nothing.
 */
final readonly class KeyDriftDetector
{
    private const string ATTRIBUTE_SHAPE = '#[TypefinderResource] shape';

    private const string PHPDOC_SHAPE = 'PHPDoc @return shape';

    private const string INERTIA = 'Inertia\\Inertia';

    /** Inertia prop wrappers that leave the prop out of the first load. */
    private const array INERTIA_WRAPPERS = [
        'Inertia\\Inertia::optional',
        'Inertia\\Inertia::lazy',
        'Inertia\\Inertia::defer',
    ];

    /** Props Inertia's own middleware shares with every page. */
    private const array INERTIA_SHARED = ['errors'];

    private PhpDocShapeResolver $phpDocShapes;

    private ReturnedArrayReader $reader;

    /** @var list<string> */
    private array $sharedPageProps;

    /**
     * @param  ?PhpDocShapeResolver  $phpDocShapes  Reads the PHPDoc shape of a
     *                                              resource that also has an attribute shape; build it without
     *                                              `onWarn` so its warnings are not repeated.
     * @param  ?ReturnedArrayReader  $reader  Defaults to one that treats
     *                                        `Inertia::optional|lazy|defer()` as conditional.
     * @param  list<string>  $sharedPageProps  Props the app shares with every
     *                                         page ({@see InertiaSharedProps}); with `errors`, never reported
     *                                         as not passed.
     */
    public function __construct(?PhpDocShapeResolver $phpDocShapes = null, ?ReturnedArrayReader $reader = null, array $sharedPageProps = [])
    {
        $this->phpDocShapes = $phpDocShapes ?? new PhpDocShapeResolver(new GeneratedClasses);
        $this->reader = $reader ?? new ReturnedArrayReader(self::INERTIA_WRAPPERS);
        $this->sharedPageProps = array_values(array_unique([...self::INERTIA_SHARED, ...array_map(strval(...), $sharedPageProps)]));
    }

    /**
     * Drift between a resource extraction's declared keys and the literal keys
     * its own `toArray()` returns. Model-typed resources (attribute `model:`
     * or the name convention) are not checked.
     *
     * @param  array{name: string, fqcn: class-string, shape: array<string, mixed>}  $extraction
     * @return list<string>
     */
    public function checkResource(array $extraction): array
    {
        $fqcn = ltrim($extraction['fqcn'], '\\');
        $shape = $extraction['shape'];

        if ($shape['kind'] === 'shape') {
            /** @var array<array-key, string> $fields */
            $fields = $shape['fields'];
            // `'note?' => 'string'` is emitted verbatim as an optional field.
            $declared = array_map(
                static fn (int|string $key): array => str_ends_with((string) $key, '?')
                    ? ['key' => substr((string) $key, 0, -1), 'optional' => true]
                    : ['key' => (string) $key, 'optional' => false],
                array_keys($fields),
            );

            return $this->unique([
                ...$this->shapeDisagreement($fqcn, array_column($declared, 'key')),
                ...$this->resourceDrift($fqcn, $declared, false, self::ATTRIBUTE_SHAPE),
            ]);
        }

        if ($shape['kind'] === 'phpdoc') {
            /** @var array{fields: list<array{name: string, optional: bool}>, open?: bool} $type */
            $type = $shape['type'];
            $declared = array_map(
                static fn (array $field): array => ['key' => (string) $field['name'], 'optional' => $field['optional']],
                $type['fields'],
            );

            return $this->unique($this->resourceDrift($fqcn, $declared, $type['open'] ?? false, self::PHPDOC_SHAPE));
        }

        return [];
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

        return $this->unique($warnings);
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
        $node = $this->methodNode(ltrim($class, '\\'), $method);

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

        return $this->unique($this->compare($declared, false, $renders, [
            'undeclared' => static fn (string $key): string => sprintf('%s::%s() passes prop "%s" to Inertia page "%s" but #[TypefinderPage] does not declare it', $class, $method, $key, $component),
            'never' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" but it is never passed', $class, $method, $key, $component),
            'notAlways' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" as required but it is not always passed (add it to optional?)', $class, $method, $key, $component),
            'conditional' => static fn (string $key): string => sprintf('%s::%s(): prop "%s" of "%s" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)', $class, $method, $key, $component),
        ], $this->sharedPageProps));
    }

    /**
     * One action's entries for one component as a single entry, merged key
     * by key like `Generator::mergePages()`, which also fails on type
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
     * @param  list<array{key: string, optional: bool}>  $declared
     * @return list<string>
     */
    private function resourceDrift(string $fqcn, array $declared, bool $declaredOpen, string $src): array
    {
        $method = $this->methodNode($fqcn, 'toArray');

        if (! $method instanceof ClassMethod) {
            return [];
        }

        $returns = $this->reader->returns($method);
        // An empty-array guard (`if ($this->resource === null) { return []; }`)
        // does not make keys "not always returned". It holds no keys and is
        // closed, so that is all dropping it changes; when every return is
        // empty, they are kept and the declared keys are never returned.
        $withKeys = array_values(array_filter($returns, static fn (ReturnedArray $returned): bool => $returned->parts !== []));

        return $this->compare($declared, $declaredOpen, $withKeys === [] ? $returns : $withKeys, [
            'undeclared' => static fn (string $key): string => sprintf('%s::toArray() returns key "%s" not declared in %s', $fqcn, $key, $src),
            'never' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" but toArray() never returns it', $fqcn, $src, $key),
            'notAlways' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" as required but toArray() does not always return it (make it optional?)', $fqcn, $src, $key),
            'conditional' => static fn (string $key): string => sprintf('%s::toArray(): "%s" is conditional in code but required in %s', $fqcn, $key, $src),
        ]);
    }

    /**
     * A resource with an attribute shape whose own `toArray()` also documents
     * a PHPDoc shape: the two key sets should agree. An inherited doc is not
     * compared, and an open PHPDoc shape (`...`) may hold the attribute's
     * extra keys.
     *
     * @param  list<string>  $attributeKeys
     * @return list<string>
     */
    private function shapeDisagreement(string $fqcn, array $attributeKeys): array
    {
        $doc = $this->methodNode($fqcn, 'toArray')?->getDocComment()?->getText();

        if ($doc === null || preg_match('/@(?:phpstan-|psalm-)?return\b/', $doc) !== 1) {
            return [];
        }

        $type = $this->phpDocShapes->resolve(new ReflectionClass($fqcn));

        if ($type === null) {
            return [];
        }

        /** @var list<array{name: string}> $fields */
        $fields = $type['fields'];
        $docKeys = array_map(static fn (array $field): string => (string) $field['name'], $fields);

        $clauses = [];
        $onlyInAttribute = ($type['open'] ?? false) ? [] : array_values(array_diff($attributeKeys, $docKeys));
        $onlyInDoc = array_values(array_diff($docKeys, $attributeKeys));

        if ($onlyInAttribute !== []) {
            $clauses[] = 'only in attribute: {'.implode(', ', $onlyInAttribute).'}';
        }

        if ($onlyInDoc !== []) {
            $clauses[] = 'only in PHPDoc: {'.implode(', ', $onlyInDoc).'}';
        }

        if ($clauses === []) {
            return [];
        }

        return [sprintf('%s: %s and %s disagree (%s)', $fqcn, self::ATTRIBUTE_SHAPE, self::PHPDOC_SHAPE, implode('; ', $clauses))];
    }

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
    private function compare(array $declared, bool $declaredOpen, array $returns, array $messages, array $shared = []): array
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
        $withs = [];

        while ($expr instanceof MethodCall && $expr->name instanceof Identifier && $expr->name->toLowerString() === 'with') {
            $withs[] = $expr;
            $expr = $expr->var;
        }

        if (! $this->isRenderCall($expr)) {
            return null;
        }

        $first = $this->argument($expr, 0, 'component');

        if (! $first instanceof String_ || $first->value !== $component) {
            return null;
        }

        $parts = $this->propsParts($expr);

        foreach (array_reverse($withs) as $with) {
            $parts = [...$parts, ...$this->withParts($with)];
        }

        return new ReturnedArray($parts);
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

                $parts[] = new ReturnedPart(ReturnedPart::ITEM, key: $arg->value->value, value: $arg->value);
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

    private function methodNode(string $fqcn, string $method): ?ClassMethod
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
    private function unique(array $warnings): array
    {
        return array_values(array_unique($warnings));
    }
}
