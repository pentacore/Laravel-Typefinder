<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;

/**
 * The shape of a resource whose own `toArray()` returns only literal-key
 * arrays, read from those keys: the last tier, for a resource with no
 * attribute, no PHPDoc shape and no model match. Null when any return holds
 * something else (a spread, a helper call, a computed key), so the resource
 * is skipped as before.
 *
 * Keys are optional when conditional (`when*()`, `whenLoaded()`,
 * `mergeWhen()`, …) or missing from another return. A value is typed where
 * that is cheap and certain, else `unknown`:
 *  - scalar literals and casts;
 *  - `new X(…)` and `X::make(…)` (`X`), `X::collection(…)` (`X[]`), for a
 *    resource X that gets a generated type;
 *  - `$this->col` / `$this->resource->col` when the class docblock names a
 *    generated model with `@mixin` (`Model['col']`, `unknown` when the model
 *    type has no such column);
 *  - a nested literal array (an object, by the same rules);
 *  - the value of `when()`, `unless()`, `whenLoaded()` and friends.
 */
final readonly class LiteralShapeResolver
{
    /** Zero-based position of the value of each conditional helper. */
    private const array VALUE_POSITION = [
        'when' => 1,
        'unless' => 1,
        'whenloaded' => 1,
        'whencounted' => 1,
        'whenhas' => 1,
        'whennotnull' => 0,
        'whennull' => 0,
        'whenappended' => 1,
    ];

    private const array UNKNOWN = ['kind' => 'ts', 'ts' => 'unknown'];

    private ReturnedArrayReader $reader;

    private ShapeComposer $composer;

    public function __construct(private GeneratedClasses $classes)
    {
        $this->reader = new ReturnedArrayReader;
        $this->composer = new ShapeComposer;
    }

    /**
     * @param  ReflectionClass<object>  $resource
     * @return ?array<string, mixed> an `object` node
     */
    public function resolve(ReflectionClass $resource): ?array
    {
        if ($resource->isAbstract() || ! $resource->isSubclassOf(JsonResource::class)) {
            return null;
        }

        $file = $resource->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);
        $method = $source?->methodNode($resource->getName(), 'toArray');

        if (! $source instanceof ParsedSource || ! $method instanceof ClassMethod) {
            return null;
        }

        return $this->shapeOf($this->reader->returns($method), $this->mixinModel($resource->getName(), $source));
    }

    /**
     * @param  list<ReturnedArray>  $returns
     * @return ?array<string, mixed>
     */
    private function shapeOf(array $returns, ?string $model): ?array
    {
        $composed = $this->composer->compose(
            $returns,
            static fn (ReturnedPart $part): ?array => null,
            fn (ReturnedPart $part): array => $part->value instanceof Expr ? $this->valueType($part->value, $model) : self::UNKNOWN,
            literalOnly: true,
        );

        return $composed === null ? null : $composed['type'];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueType(Expr $value, ?string $model): array
    {
        return match (true) {
            $value instanceof String_, $value instanceof InterpolatedString, $value instanceof Cast\String_ => $this->ts('string'),
            $value instanceof Int_, $value instanceof Float_, $value instanceof Cast\Int_, $value instanceof Cast\Double => $this->ts('number'),
            $value instanceof Cast\Bool_ => $this->ts('boolean'),
            $value instanceof ConstFetch => $this->constant($value),
            $value instanceof Array_ => $this->shapeOf([$this->reader->read($value)], $model) ?? self::UNKNOWN,
            $value instanceof New_ => $value->class instanceof Name ? $this->resource($value->class->toString()) : self::UNKNOWN,
            $value instanceof StaticCall => $this->staticCall($value),
            $value instanceof PropertyFetch => $this->property($value, $model),
            $value instanceof MethodCall => $this->conditionalValue($value, $model),
            default => self::UNKNOWN,
        };
    }

    /** @return array<string, mixed> */
    private function constant(ConstFetch $value): array
    {
        return match ($value->name->toLowerString()) {
            'true', 'false' => $this->ts('boolean'),
            'null' => $this->ts('null'),
            default => self::UNKNOWN,
        };
    }

    /** @return array<string, mixed> */
    private function staticCall(StaticCall $call): array
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return self::UNKNOWN;
        }

        return match ($call->name->toLowerString()) {
            'make' => $this->resource($call->class->toString()),
            'collection' => ($resource = $this->resource($call->class->toString())) === self::UNKNOWN
                ? self::UNKNOWN
                : ['kind' => 'array', 'of' => $resource],
            default => self::UNKNOWN,
        };
    }

    /** @return array<string, mixed> */
    private function resource(string $fqcn): array
    {
        return in_array(strtolower($fqcn), ['self', 'static', 'parent'], true) || ! $this->classes->isResource($fqcn)
            ? self::UNKNOWN
            : ['kind' => 'resource', 'fqcn' => ltrim($fqcn, '\\')];
    }

    /**
     * `$this->col` or `$this->resource->col` of a `@mixin` model.
     *
     * @return array<string, mixed>
     */
    private function property(PropertyFetch $fetch, ?string $model): array
    {
        if ($model === null || ! $fetch->name instanceof Identifier) {
            return self::UNKNOWN;
        }

        $object = $fetch->var;

        if ($object instanceof PropertyFetch
            && $object->name instanceof Identifier
            && $object->name->toString() === 'resource') {
            $object = $object->var;
        }

        return $object instanceof Variable && $object->name === 'this'
            ? ['kind' => 'property', 'fqcn' => $model, 'name' => $fetch->name->toString()]
            : self::UNKNOWN;
    }

    /**
     * The value of `$this->when($c, $value)` and its friends (an arrow
     * function's body); the key is already optional.
     *
     * @return array<string, mixed>
     */
    private function conditionalValue(MethodCall $call, ?string $model): array
    {
        if (! $call->var instanceof Variable || $call->var->name !== 'this' || ! $call->name instanceof Identifier) {
            return self::UNKNOWN;
        }

        $position = self::VALUE_POSITION[$call->name->toLowerString()] ?? null;
        $arg = $position === null ? null : ($call->args[$position] ?? null);

        if (! $arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
            return self::UNKNOWN;
        }

        $value = $arg->value instanceof ArrowFunction ? $arg->value->expr : $arg->value;

        return $this->valueType($value, $model);
    }

    /**
     * The generated model a `@mixin` tag in the class docblock names.
     *
     * @return ?class-string
     */
    private function mixinModel(string $class, ParsedSource $source): ?string
    {
        $doc = $source->classDoc($class);

        if ($doc === null || preg_match_all('/@mixin\s+(\\\\?[A-Za-z_][\w\\\\]*)/', $doc, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $name) {
            $fqcn = $source->resolveName($class, $name);

            if ($this->classes->isModel($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /** @return array{kind: 'ts', ts: string} */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }
}
