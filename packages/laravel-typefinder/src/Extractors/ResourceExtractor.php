<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderResource;
use Pentacore\Typefinder\Resolvers\LiteralShapeResolver;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\GeneratedClasses;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Throwable;

class ResourceExtractor
{
    private readonly GeneratedClasses $generatedClasses;

    private readonly PhpDocShapeResolver $phpDocShapeResolver;

    private readonly LiteralShapeResolver $literalShapeResolver;

    /**
     * @param  bool  $inferFromModelName  Fall back to the `UserResource` → `User` name
     *                                    convention when a resource has no attribute or PHPDoc shape.
     * @param  ?list<class-string>  $modelClasses  Models the convention may match. These should
     *                                             be the models that get a generated type. When
     *                                             null, they are discovered from `typefinder.models.paths`.
     * @param  ?list<class-string>  $enumClasses  Enums that get a generated type; null discovers them from config.
     * @param  ?list<class-string>  $resourceClasses  Resources that get a generated type; null discovers them from config.
     * @param  null|callable(string): void  $onWarn  Receives warnings about PHPDoc shapes (unmappable types, unparsable docs, composed keys with no documented type).
     */
    public function __construct(
        private readonly bool $inferFromModelName = true,
        ?array $modelClasses = null,
        ?array $enumClasses = null,
        ?array $resourceClasses = null,
        ?callable $onWarn = null,
    ) {
        $this->generatedClasses = new GeneratedClasses($modelClasses, $enumClasses, $resourceClasses);
        $this->phpDocShapeResolver = new PhpDocShapeResolver($this->generatedClasses, $onWarn);
        $this->literalShapeResolver = new LiteralShapeResolver($this->generatedClasses);
    }

    /**
     * @return ?array{name: string, fqcn: class-string, shape: array}
     */
    public function extract(string $resourceClass): ?array
    {
        $reflectionClass = new ReflectionClass($resourceClass);

        if (! $reflectionClass->isSubclassOf(JsonResource::class)) {
            return null;
        }

        if ($reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return null;
        }

        $attribute = $this->getAttribute($reflectionClass);

        if ($attribute instanceof TypefinderResource && $attribute->shape !== [] && $attribute->model !== null) {
            throw new RuntimeException(
                sprintf('TypefinderResource on %s: `shape` and `model` are mutually exclusive.', $resourceClass),
            );
        }

        if ($attribute instanceof TypefinderResource && $attribute->shape !== []) {
            return [
                'name' => $reflectionClass->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => ['kind' => 'shape', 'fields' => $attribute->shape],
            ];
        }

        if ($attribute instanceof TypefinderResource && $attribute->model !== null) {
            if (! class_exists($attribute->model) || ! is_subclass_of($attribute->model, Model::class)) {
                return null;
            }

            return [
                'name' => $reflectionClass->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => [
                    'kind' => 'model',
                    'model' => $attribute->model,
                    'omit' => $attribute->omit,
                    'extend' => $attribute->extend,
                    'via' => 'attribute',
                ],
            ];
        }

        // A documented toArray() shape is a declaration, so it applies even
        // with name-convention inference off. The resolver skips abstract
        // resources (never returned by an API), which fall through as before.
        $type = $this->phpDocShapeResolver->resolve($reflectionClass);
        if ($type !== null) {
            return [
                'name' => $reflectionClass->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => ['kind' => 'phpdoc', 'type' => $type],
            ];
        }

        $collected = $this->collectedResource($reflectionClass);
        if ($collected !== null) {
            return [
                'name' => $reflectionClass->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => ['kind' => 'collection', 'type' => ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => $collected]]],
            ];
        }

        $short = $reflectionClass->getShortName();
        if ($this->inferFromModelName && str_ends_with($short, 'Resource')) {
            $modelShort = substr($short, 0, -strlen('Resource'));
            $modelFqcn = $this->resolveModelFqcn($modelShort);
            if ($modelFqcn !== null) {
                return [
                    'name' => $short,
                    'fqcn' => $resourceClass,
                    'shape' => [
                        'kind' => 'model',
                        'model' => $modelFqcn,
                        'omit' => [],
                        'extend' => [],
                        'via' => 'convention',
                    ],
                ];
            }
        }

        // Last: the literal keys its own toArray() returns.
        $type = $this->literalShapeResolver->resolve($reflectionClass);
        if ($type !== null) {
            return [
                'name' => $short,
                'fqcn' => $resourceClass,
                'shape' => ['kind' => 'inferred', 'type' => $type],
            ];
        }

        return null;
    }

    /**
     * Whether a class gets a resource type of its own: a `JsonResource`, not
     * `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $resourceClass
     */
    public function isEmittable(string $resourceClass): bool
    {
        $reflectionClass = new ReflectionClass($resourceClass);

        return $reflectionClass->isSubclassOf(JsonResource::class)
            && $reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }

    /**
     * {@see self::extract()} for an emittable resource, or null when it has
     * no resolvable shape or cannot be extracted: `$onWarn` is told why.
     *
     * @param  class-string  $resourceClass
     * @param  null|callable(string, Throwable): void  $onWarn
     * @return ?array{name: string, fqcn: class-string, shape: array}
     */
    public function tryExtract(string $resourceClass, ?callable $onWarn = null): ?array
    {
        try {
            $entry = $this->extract($resourceClass);
            if ($entry === null) {
                throw new RuntimeException($this->unresolvedMessage());
            }

            return $entry;
        } catch (Throwable $throwable) {
            if ($onWarn !== null) {
                $onWarn($resourceClass, $throwable);
            }

            return null;
        }
    }

    /**
     * @return list<array{name: string, fqcn: class-string, shape: array}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        $results = [];

        foreach (ClassDiscovery::in($path, $onWarn) as $className) {
            if (! $this->isEmittable($className)) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $entry = $this->tryExtract($className, $onWarn);
            if ($entry !== null) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * The resource a `ResourceCollection` subclass maps its items to, found
     * the way Laravel's `collects()` does: `#[Collects]`, then `$collects`,
     * then `XCollection` → `X` or `XResource` in the same namespace. Null when
     * it is not a collection, overrides `toArray()` or `collects()` (its own
     * items are then not the collected resource's), or collects no resource.
     *
     * @param  ReflectionClass<object>  $class
     * @return ?class-string
     */
    private function collectedResource(ReflectionClass $class): ?string
    {
        if (! $class->isSubclassOf(ResourceCollection::class)
            || $class->getMethod('toArray')->getDeclaringClass()->getName() !== ResourceCollection::class
            || $class->getMethod('collects')->getDeclaringClass()->isSubclassOf(ResourceCollection::class)) {
            return null;
        }

        $candidates = [];

        // Laravel 13's attribute; named by string so Laravel 12 does not need the class.
        $attribute = $class->getAttributes(Collects::class)[0] ?? null;
        $collects = $class->getDefaultProperties()['collects'] ?? null;

        if ($attribute !== null) {
            $arguments = $attribute->getArguments();
            $candidates[] = (string) ($arguments[0] ?? $arguments['class'] ?? '');
        } elseif (is_string($collects) && $collects !== '') {
            $candidates[] = $collects;
        } elseif (str_ends_with($class->getShortName(), 'Collection')) {
            $base = substr($class->getName(), 0, -strlen('Collection'));
            $candidates = [$base, $base.'Resource'];
        }

        foreach ($candidates as $candidate) {
            $candidate = ltrim($candidate, '\\');

            if (class_exists($candidate)) {
                return is_subclass_of($candidate, JsonResource::class) ? $candidate : null;
            }
        }

        return null;
    }

    protected function getAttribute(ReflectionClass $reflectionClass): ?TypefinderResource
    {
        $attrs = $reflectionClass->getAttributes(TypefinderResource::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Why a resource produced no type. Shared by full and incremental runs.
     */
    public function unresolvedMessage(): string
    {
        if (! $this->inferFromModelName) {
            return 'No #[TypefinderResource] attribute, and name-convention inference is off '
                .'(typefinder.resources.infer_from_model_name). Add a shape or model attribute, '
                .'or a PHPDoc @return array{…} on toArray(), '
                .'or #[TypefinderIgnore] to skip this resource.';
        }

        return 'No #[TypefinderResource] attribute and class name does not match any discovered model. '
            .'Add a shape or model attribute, or a PHPDoc @return array{…} on toArray(), to include this resource.';
    }

    /** @var ?array<string, class-string> */
    protected ?array $modelShortNameMap = null;

    /**
     * Look up a model FQCN by short name for the name convention.
     *
     * Only models that get a generated type are candidates: a match on any
     * other Model subclass (a vendor model, or one not autoloaded yet in an
     * incremental run) would import a type that does not exist.
     */
    protected function resolveModelFqcn(string $shortName): ?string
    {
        if ($this->modelShortNameMap === null) {
            $this->modelShortNameMap = [];
            foreach ($this->generatedClasses->models() as $class) {
                $this->modelShortNameMap[new ReflectionClass($class)->getShortName()] ??= $class;
            }
        }

        return $this->modelShortNameMap[$shortName] ?? null;
    }
}
