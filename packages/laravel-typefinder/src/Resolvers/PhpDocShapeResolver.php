<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Reads the PHPDoc return shape of a resource's `toArray()` from disk and
 * maps it to a type tree. Null means "no shape here" and the caller falls
 * through to the next tier.
 *
 * Without a `@return` shape on its own `toArray()`, a resource whose
 * `toArray()` body returns parts of documented helper methods
 * (`$this->m()`, `self::m()`, `static::m()`) or of a documented
 * `parent::toArray()` gets the shape composed from those parts
 * ({@see ShapeComposer}). An undocumented helper is composed from its own
 * body the same way, up to {@see self::MAX_DELEGATION_DEPTH} delegations
 * from `toArray()`; an undocumented helper or parent `toArray()` whose body
 * uses `merge()`, `mergeWhen()` or `mergeUnless()` is not. Any part that
 * cannot be typed means no composition.
 */
final class PhpDocShapeResolver
{
    /** Delegations followed from `toArray()` (helper calls and `parent::toArray()`). */
    private const int MAX_DELEGATION_DEPTH = 3;

    private const array UNKNOWN = ['kind' => 'ts', 'ts' => 'unknown'];

    /**
     * Marks a composed field typed `unknown` because a literal key gave it no
     * documented type; replaced by plain `unknown` before resolve() returns.
     */
    private const array UNDOCUMENTED = ['kind' => 'ts', 'ts' => 'unknown', 'undocumented' => true];

    private readonly PhpDocTypeMapper $phpDocTypeMapper;

    private readonly ?Closure $onWarn;

    private readonly PhpDocReader $docs;

    private readonly ShapeComposer $shapeComposer;

    private readonly ReturnedArrayReader $returnedArrayReader;

    /**
     * Warnings held back while a composition may still fail (innermost
     * last): a failed composition falls through silently.
     *
     * @var list<list<string>>
     */
    private array $heldWarnings = [];

    /**
     * @param  null|callable(string): void  $onWarn
     */
    public function __construct(GeneratedClasses $classes, ?callable $onWarn = null)
    {
        $this->onWarn = $onWarn === null ? null : $onWarn(...);
        $this->phpDocTypeMapper = new PhpDocTypeMapper($classes, $this->warn(...));
        $this->shapeComposer = new ShapeComposer;
        $this->returnedArrayReader = new ReturnedArrayReader;
        $this->docs = new PhpDocReader($this->warn(...));
    }

    /**
     * The tier-4 type of a resource: a `@return` shape on its own
     * `toArray()`; else a shape composed from that `toArray()`'s body when
     * the resource declares it; else a doc inherited from a parent or trait.
     *
     * @param  ReflectionClass<object>  $resource
     * @return ?array<string, mixed> an `object` node
     */
    public function resolve(ReflectionClass $resource): ?array
    {
        if ($resource->isAbstract() || ! $resource->isSubclassOf(JsonResource::class)) {
            return null;
        }

        $scopes = [];
        $type = $this->tierFour($resource, $resource->getName(), 0, [], $scopes);

        return $type === null ? null : $this->settle($type, $resource->getName());
    }

    /**
     * Only the documented `@return` shape (own or inherited), never a
     * composed one.
     *
     * @param  ReflectionClass<object>  $resource
     * @return ?array<string, mixed> an `object` node
     */
    public function resolveDocumented(ReflectionClass $resource): ?array
    {
        if ($resource->isAbstract() || ! $resource->isSubclassOf(JsonResource::class)) {
            return null;
        }

        $scopes = [];

        return $this->documentedShape($resource, $resource->getName(), $scopes);
    }

    /**
     * Tier 4 for $class's `toArray()`, read for $resource (what `static`
     * means). Also used for `parent::toArray()`, so $class may be abstract.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, true>  $visiting  methods whose body is being composed
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function tierFour(ReflectionClass $class, string $resource, int $depth, array $visiting, array &$scopes): ?array
    {
        $own = $this->ownMethodSource($class, 'toArray');
        $ownHasReturnTag = $own !== null && $this->docs->hasReturnTag($this->docs->parseDoc($own[1]->methodDoc($own[0], 'toArray')));

        if ($own === null || $ownHasReturnTag) {
            $documented = $this->documentedShape($class, $resource, $scopes);

            if ($documented !== null || $own === null) {
                return $documented;
            }
        }

        $node = $own[1]->methodNode($own[0], 'toArray');
        $composed = $node instanceof ClassMethod
            ? $this->composeMethod($own[0], $own[0], $node, $resource, $depth, $visiting, $scopes)
            : null;

        if ($composed !== null || $ownHasReturnTag) {
            return $composed;
        }

        return $this->documentedShape($class, $resource, $scopes);
    }

    /**
     * Phase 1: the `toArray()` doc shape, walking up past undocumented
     * overrides.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function documentedShape(ReflectionClass $class, string $resource, array &$scopes): ?array
    {
        $found = $this->findReturnType($class, $resource);

        if ($found === null) {
            return null;
        }

        [$type, $declaring] = $found;
        $scope = $this->docs->scopeFor($declaring, $resource, $scopes);
        $shape = $scope instanceof PhpDocScope ? $this->docs->shapeOf($type, $scope) : null;

        return $shape === null ? null : $this->phpDocTypeMapper->map($shape[0], $shape[1], '');
    }

    /**
     * Compose the arrays a method returns. Warnings raised meanwhile are
     * kept only when it composes.
     *
     * @param  class-string  $context  the class the code belongs to (`self::`, `parent::`); the using class for trait code
     * @param  class-string  $owner  the class or trait whose file declares the method
     * @param  array<string, true>  $visiting
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function composeMethod(string $context, string $owner, ClassMethod $method, string $resource, int $depth, array $visiting, array &$scopes): ?array
    {
        $key = strtolower($owner.'::'.$method->name->toString());

        if (isset($visiting[$key])) {
            return null;
        }

        $returns = $this->returnedArrayReader->returns($method);

        // A delegated body (helper, parent `toArray()`; depth > 0) returns its
        // merge calls raw: they are spliced into the caller's array, with the
        // caller's keys, so their precedence there cannot be told.
        if ($depth > 0 && array_any($returns, static fn (ReturnedArray $returned): bool => array_any(
            $returned->parts,
            static fn (ReturnedPart $part): bool => $part->merged !== null,
        ))) {
            return null;
        }

        $visiting[$key] = true;
        $this->heldWarnings[] = [];

        try {
            $composed = $this->shapeComposer->compose(
                $returns,
                function (ReturnedPart $part) use ($context, $resource, $depth, $visiting, &$scopes): ?array {
                    return $this->delegatedShape($part->expr, $context, $resource, $depth + 1, $visiting, $scopes);
                },
            );
        } finally {
            $held = array_pop($this->heldWarnings) ?? [];
        }

        if ($composed === null) {
            return null;
        }

        // A helper used by several parts or returns raised its warnings once per use.
        foreach (array_unique($held) as $message) {
            $this->warn($message);
        }

        return $this->markUndocumented($composed['type'], $composed['undocumented']);
    }

    /**
     * The object node a delegated expression returns: `$this->m()`,
     * `self::m()`, `static::m()` or `parent::toArray()`. Null for anything
     * else, or past the depth limit.
     *
     * @param  array<string, true>  $visiting
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function delegatedShape(?Expr $expr, string $context, string $resource, int $depth, array $visiting, array &$scopes): ?array
    {
        if ($depth > self::MAX_DELEGATION_DEPTH) {
            return null;
        }

        if ($expr instanceof MethodCall
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && ! $expr->isFirstClassCallable()) {
            $method = $expr->name->toString();

            return $this->helperShape($this->dispatchClass($context, $method, $resource), $method, $resource, $depth, $visiting, $scopes);
        }

        if (! $expr instanceof StaticCall
            || ! $expr->class instanceof Name
            || ! $expr->name instanceof Identifier
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $method = $expr->name->toString();

        return match ($expr->class->toLowerString()) {
            'static' => $this->helperShape($resource, $method, $resource, $depth, $visiting, $scopes),
            'self' => $this->helperShape($context, $method, $resource, $depth, $visiting, $scopes),
            'parent' => strtolower($method) === 'toarray' ? $this->parentShape($context, $resource, $depth, $visiting, $scopes) : null,
            default => null,
        };
    }

    /**
     * Where `$this->m()` in $context's code dispatches: a private `m` that
     * $context declares itself (trait methods are copied into the using
     * class) is called as is; anything else binds late, on the resource.
     */
    private function dispatchClass(string $context, string $method, string $resource): string
    {
        $located = $this->locateMethod($context, $method);

        return $located !== null && $located['private'] && $located['context'] === $context ? $context : $resource;
    }

    /**
     * A helper's own `@return` shape, read in its declaring class or trait
     * file; without one, its body composed.
     *
     * @param  string  $lookup  the class the method is looked up on
     * @param  array<string, true>  $visiting
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function helperShape(string $lookup, string $method, string $resource, int $depth, array $visiting, array &$scopes): ?array
    {
        $located = $this->locateMethod($lookup, $method);

        if ($located === null) {
            return null;
        }

        ['context' => $context, 'owner' => $owner, 'node' => $node] = $located;

        $type = $this->docs->returnType($this->docs->parseDoc($node->getDocComment()?->getText()));
        $scope = $type instanceof TypeNode ? $this->docs->scopeFor($owner, $resource, $scopes) : null;
        $shape = $type instanceof TypeNode && $scope instanceof PhpDocScope ? $this->docs->shapeOf($type, $scope) : null;

        if ($shape !== null) {
            return $this->phpDocTypeMapper->map($shape[0], $shape[1], '', $node->name->toString());
        }

        return $this->composeMethod($context, $owner, $node, $resource, $depth, $visiting, $scopes);
    }

    /**
     * Find $method as called on $lookup, disk first: `typefinder:watch`
     * keeps classes loaded, so reflection misses a method added or renamed
     * since, while `toArray()` is read fresh. $lookup's own file is read
     * first; only a method it does not declare is looked up by reflection
     * (inherited or trait-provided) and then read from its declaring file.
     * Null when it is found nowhere on disk.
     *
     * `context` is the class the code belongs to (the using class for trait
     * code), `owner` the class or trait whose file declares it.
     *
     * @return ?array{context: class-string, owner: class-string, node: ClassMethod, private: bool}
     */
    private function locateMethod(string $lookup, string $method): ?array
    {
        try {
            $class = new ReflectionClass($lookup);
        } catch (ReflectionException) {
            return null;
        }

        $own = $this->ownMethodSource($class, $method);
        $node = $own === null ? null : $own[1]->methodNode($own[0], $method);

        if ($node instanceof ClassMethod) {
            return ['context' => $class->getName(), 'owner' => $class->getName(), 'node' => $node, 'private' => $node->isPrivate()];
        }

        try {
            $reflection = new ReflectionMethod($lookup, $method);
        } catch (ReflectionException) {
            return null;
        }

        $declaring = $reflection->getDeclaringClass();
        $source = $this->ownMethodSource($declaring, $method) ?? $this->traitMethodSource($declaring, $method);
        $node = $source === null ? null : $source[1]->methodNode($source[0], $method);

        return $node instanceof ClassMethod
            ? ['context' => $declaring->getName(), 'owner' => $source[0], 'node' => $node, 'private' => $reflection->isPrivate()]
            : null;
    }

    /**
     * `parent::toArray()` in $context: the tier-4 type of the ancestor that
     * declares the inherited `toArray()`. Not composable when that is
     * JsonResource itself (the model's attributes).
     *
     * @param  array<string, true>  $visiting
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array<string, mixed>
     */
    private function parentShape(string $context, string $resource, int $depth, array $visiting, array &$scopes): ?array
    {
        try {
            $parent = new ReflectionClass($context)->getParentClass();
        } catch (ReflectionException) {
            return null;
        }

        $declaring = $parent === false ? null : $this->toArrayDeclarer($parent->getName());

        if (! $declaring instanceof ReflectionClass || $declaring->getName() === JsonResource::class) {
            return null;
        }

        return $this->tierFour($declaring, $resource, $depth, $visiting, $scopes);
    }

    /**
     * Type the fields a composition's literal keys left undocumented with
     * the {@see self::UNDOCUMENTED} marker, so a caller composing this
     * shape in turn still knows them.
     *
     * @param  array<string, mixed>  $type
     * @param  list<string>  $undocumented
     * @return array<string, mixed>
     */
    private function markUndocumented(array $type, array $undocumented): array
    {
        foreach ($type['fields'] as $index => $field) {
            if (! in_array((string) $field['name'], $undocumented, true)) {
                continue;
            }

            $members = ($field['type']['kind'] ?? null) === 'union' ? $field['type']['of'] : [$field['type']];
            $members = array_map(static fn (array $member): array => $member === self::UNKNOWN ? self::UNDOCUMENTED : $member, $members);
            $type['fields'][$index]['type'] = count($members) === 1 ? $members[0] : ['kind' => 'union', 'of' => $members];
        }

        return $type;
    }

    /**
     * Replace the {@see self::UNDOCUMENTED} markers by `unknown` and warn
     * once about the keys that had them.
     *
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private function settle(array $type, string $resource): array
    {
        $undocumented = [];

        foreach ($type['fields'] as $index => $field) {
            $members = ($field['type']['kind'] ?? null) === 'union' ? $field['type']['of'] : [$field['type']];

            if (! in_array(self::UNDOCUMENTED, $members, true)) {
                continue;
            }

            $undocumented[] = '"'.$field['name'].'"';
            $plain = [];

            foreach ($members as $member) {
                $member = $member === self::UNDOCUMENTED ? self::UNKNOWN : $member;

                if (! in_array($member, $plain, true)) {
                    $plain[] = $member;
                }
            }

            $type['fields'][$index]['type'] = count($plain) === 1 ? $plain[0] : ['kind' => 'union', 'of' => $plain];
        }

        if ($undocumented !== []) {
            $this->warn(sprintf(
                '%s::toArray() composes a shape but %s %s no documented type — add @return array{…} to toArray()',
                $resource,
                implode(', ', $undocumented),
                count($undocumented) === 1 ? 'has' : 'have',
            ));
        }

        return $type;
    }

    /**
     * The winning `@*return` type of `toArray()` and the class or trait whose
     * doc declares it, walking up past docs without a return tag (no doc, or
     * only inheritDoc) until `JsonResource`.
     *
     * @param  ReflectionClass<object>  $start  the class whose `toArray()` is read
     * @param  string  $resource  named in warnings
     * @return ?array{TypeNode, class-string}
     */
    private function findReturnType(ReflectionClass $start, string $resource): ?array
    {
        $class = $this->toArrayDeclarer($start->getName());

        while ($class instanceof ReflectionClass && $class->getName() !== JsonResource::class) {
            // A trait-provided toArray() reports the using class as declaring
            // it, but the method (and its doc) lives in the trait's file.
            $owner = $this->ownMethodSource($class, 'toArray') ?? $this->traitMethodSource($class, 'toArray');

            if ($owner === null) {
                return null;
            }

            [$ownerName, $source] = $owner;
            $doc = $this->docs->parseDoc($source->methodDoc($ownerName, 'toArray'));

            if (! $doc instanceof PhpDocNode || ! $this->docs->hasReturnTag($doc)) {
                $parent = $class->getParentClass();

                if ($parent === false) {
                    return null;
                }

                $class = $this->toArrayDeclarer($parent->getName());

                continue;
            }

            foreach (PhpDocReader::RETURN_TAGS as $rank => $tag) {
                $values = $doc->getReturnTagValues($tag);

                if ($values !== []) {
                    // A broken higher-precedence tag still deserves its warning.
                    $this->docs->warnUnparsable($doc, $resource, array_slice(PhpDocReader::RETURN_TAGS, 0, $rank));

                    return [$values[0]->type, $ownerName];
                }
            }

            $this->docs->warnUnparsable($doc, $resource, PhpDocReader::RETURN_TAGS);

            return null;
        }

        return null;
    }

    /**
     * The class whose code declares the `toArray()` that $class has, disk
     * first ({@see self::locateMethod()}).
     *
     * @return ?ReflectionClass<object>
     */
    private function toArrayDeclarer(string $class): ?ReflectionClass
    {
        $declaring = $this->locateMethod($class, 'toArray')['context'] ?? null;

        return $declaring === null ? null : new ReflectionClass($declaring);
    }

    /**
     * The class itself and its parsed file, when that file declares $method.
     *
     * @param  ReflectionClass<object>  $class
     * @return ?array{class-string, ParsedSource}
     */
    private function ownMethodSource(ReflectionClass $class, string $method): ?array
    {
        $file = $class->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);

        return $source?->methodNode($class->getName(), $method) instanceof ClassMethod
            ? [$class->getName(), $source]
            : null;
    }

    /**
     * The first trait (in `use` order, depth-first through traits used by
     * traits) whose own file declares $method.
     *
     * @param  ReflectionClass<object>  $class
     * @return ?array{class-string, ParsedSource}
     */
    private function traitMethodSource(ReflectionClass $class, string $method): ?array
    {
        foreach ($class->getTraits() as $trait) {
            $found = $this->ownMethodSource($trait, $method) ?? $this->traitMethodSource($trait, $method);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function warn(string $message): void
    {
        if ($this->heldWarnings !== []) {
            $this->heldWarnings[array_key_last($this->heldWarnings)][] = $message;

            return;
        }

        if ($this->onWarn instanceof Closure) {
            ($this->onWarn)($message);
        }
    }
}
