<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderResource;
use Pentacore\Typefinder\Support\ClassNameResolver;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

class ResourceExtractor
{
    /**
     * @param  bool  $inferFromModelName  Fall back to the `UserResource` → `User` name
     *                                    convention when a resource has no attribute.
     * @param  ?list<class-string>  $modelClasses  Models the convention may match. These should
     *                                             be the models that get a generated type. When
     *                                             null, they are discovered from `typefinder.models.paths`.
     */
    public function __construct(
        private readonly bool $inferFromModelName = true,
        private readonly ?array $modelClasses = null,
    ) {}

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
                ],
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
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, fqcn: class-string, shape: array}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $results = [];
        $finder = Finder::create()->files()->name('*.php')->in($path);

        foreach ($finder as $file) {
            $className = ClassNameResolver::fromFile($file->getRealPath());
            if ($className === null) {
                continue;
            }

            if (! class_exists($className)) {
                $failure = ClassNameResolver::autoloadFailure($className, $file->getRealPath());
                if ($failure instanceof RuntimeException && $onWarn !== null) {
                    $onWarn($className, $failure);
                }

                continue;
            }

            $reflection = new ReflectionClass($className);
            if (! $reflection->isSubclassOf(JsonResource::class)) {
                continue;
            }

            if ($reflection->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            try {
                $entry = $this->extract($className);
                if ($entry === null) {
                    if ($onWarn !== null) {
                        $onWarn($className, new RuntimeException($this->unresolvedMessage()));
                    }

                    continue;
                }

                $results[] = $entry;
            } catch (Throwable $throwable) {
                if ($onWarn !== null) {
                    $onWarn($className, $throwable);
                }
            }
        }

        return $results;
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
                .'or #[TypefinderIgnore] to skip this resource.';
        }

        return 'No #[TypefinderResource] attribute and class name does not match any discovered model. '
            .'Add a shape or model attribute to include this resource.';
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
            foreach ($this->modelClasses ?? $this->discoverModelClasses() as $class) {
                $this->modelShortNameMap[new ReflectionClass($class)->getShortName()] ??= $class;
            }
        }

        return $this->modelShortNameMap[$shortName] ?? null;
    }

    /**
     * The models a full run would emit: concrete Model subclasses under
     * `typefinder.models.paths` without #[TypefinderIgnore].
     *
     * @return list<class-string>
     */
    protected function discoverModelClasses(): array
    {
        if (! config('typefinder.models.enabled', true)) {
            return [];
        }

        $classes = [];
        foreach ((array) config('typefinder.models.paths', []) as $path) {
            if (! is_dir((string) $path)) {
                continue;
            }

            foreach (Finder::create()->files()->name('*.php')->in((string) $path)->sortByName() as $file) {
                $className = ClassNameResolver::fromFile($file->getRealPath());
                if ($className === null || ! class_exists($className) || ! is_subclass_of($className, Model::class)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                if ($reflection->isAbstract()) {
                    continue;
                }

                if ($reflection->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    continue;
                }

                $classes[] = $className;
            }
        }

        return $classes;
    }
}
