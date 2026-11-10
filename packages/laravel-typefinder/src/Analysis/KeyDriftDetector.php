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

    private PhpDocShapeResolver $phpDocShapes;

    private ReturnedArrayReader $reader;

    /**
     * @param  ?PhpDocShapeResolver  $phpDocShapes  Reads the PHPDoc shape of a
     *                                              resource that also has an attribute shape; build it without
     *                                              `onWarn` so its warnings are not repeated.
     * @param  ?ReturnedArrayReader  $reader  Defaults to one that treats
     *                                        `Inertia::optional|lazy|defer()` as conditional.
     */
    public function __construct(?PhpDocShapeResolver $phpDocShapes = null, ?ReturnedArrayReader $reader = null)
    {
        $this->phpDocShapes = $phpDocShapes ?? new PhpDocShapeResolver(new GeneratedClasses);
        $this->reader = $reader ?? new ReturnedArrayReader(self::INERTIA_WRAPPERS);
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
     * Drift between a `#[TypefinderPage]` and the props its own action
     * passes to `Inertia::render('X', …)` / `inertia('X', …)` for the same
     * component (each matching call, with any chained `->with()`, is one
     * set of props). An action that renders elsewhere (a helper, a base
     * controller) has no matching call and reports nothing.
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

        return $this->unique($this->compare($declared, false, $this->renders($node, $component), [
            'undeclared' => static fn (string $key): string => sprintf('%s::%s() passes prop "%s" to Inertia page "%s" but #[TypefinderPage] does not declare it', $class, $method, $key, $component),
            'never' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" but it is never passed', $class, $method, $key, $component),
            'notAlways' => static fn (string $key): string => sprintf('%s::%s(): #[TypefinderPage] declares prop "%s" for "%s" as required but it is not always passed (add it to optional?)', $class, $method, $key, $component),
            'conditional' => static fn (string $key): string => sprintf('%s::%s(): prop "%s" of "%s" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)', $class, $method, $key, $component),
        ]));
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

        return $this->compare($declared, $declaredOpen, $this->reader->returns($method), [
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

        try {
            $type = $this->phpDocShapes->resolve(new ReflectionClass($fqcn));
        } catch (Throwable) {
            return [];
        }

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
     * return: the open part may supply it unconditionally.
     *
     * @param  list<array{key: string, optional: bool}>  $declared
     * @param  list<ReturnedArray>  $returns
     * @param  array{undeclared: Closure(string): string, never: Closure(string): string, notAlways: Closure(string): string, conditional: Closure(string): string}  $messages
     * @return list<string>
     */
    private function compare(array $declared, bool $declaredOpen, array $returns, array $messages): array
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

            if ($flags === []) {
                if ($allClosed) {
                    $warnings[] = $messages['never']($key);
                }

                continue;
            }

            if ($allClosed && count($flags) < count($returns)) {
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
     * added later, so its props are open.
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

            /** @param  Closure(Expr, string): ?ReturnedArray  $match */
            public function __construct(private readonly Closure $match, private readonly string $component) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof ClosureExpr || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_ && $node->expr instanceof Expr) {
                    $this->markReturned($node->expr);
                }

                $props = $node instanceof Expr ? ($this->match)($node, $this->component) : null;

                if (! $props instanceof ReturnedArray) {
                    return null;
                }

                $this->renders[] = isset($this->returned[spl_object_id($node)])
                    ? $props
                    : new ReturnedArray([...$props->parts, new ReturnedPart(ReturnedPart::EXPR, expr: $node)]);

                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
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

        return $visitor->renders;
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
