<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use Symfony\Component\Finder\Finder;

/**
 * Answers "does this class get a generated TypeScript type?" for models,
 * enums and resources. A null list is discovered lazily from config and
 * memoised per instance; an explicit list skips discovery.
 */
final class GeneratedClasses
{
    /** @var ?array<class-string, true> */
    private ?array $modelSet = null;

    /** @var ?array<class-string, true> */
    private ?array $enumSet = null;

    /** @var ?array<class-string, true> */
    private ?array $resourceSet = null;

    /**
     * @param  ?list<class-string>  $models
     * @param  ?list<class-string>  $enums
     * @param  ?list<class-string>  $resources
     */
    public function __construct(
        private ?array $models = null,
        private ?array $enums = null,
        private ?array $resources = null,
    ) {}

    /**
     * @return list<class-string>
     */
    public function models(): array
    {
        return $this->models ??= $this->discover(
            'models',
            static fn (string $class): ?ReflectionClass => class_exists($class) && is_subclass_of($class, Model::class)
                ? new ReflectionClass($class)
                : null,
            static fn (ReflectionClass $r): bool => ! $r->isAbstract(),
        );
    }

    /**
     * @return list<class-string>
     */
    private function enums(): array
    {
        return $this->enums ??= $this->discover(
            'enums',
            static fn (string $class): ?ReflectionClass => enum_exists($class) ? new ReflectionEnum($class) : null,
            static fn (ReflectionClass $r): bool => $r instanceof ReflectionEnum && $r->isBacked(),
        );
    }

    /**
     * @return list<class-string>
     */
    private function resources(): array
    {
        return $this->resources ??= $this->discover(
            'resources',
            static fn (string $class): ?ReflectionClass => class_exists($class) && is_subclass_of($class, JsonResource::class)
                ? new ReflectionClass($class)
                : null,
            static fn (ReflectionClass $r): bool => ! $r->isAbstract(),
        );
    }

    public function isModel(string $fqcn): bool
    {
        return isset($this->modelSet()[ltrim($fqcn, '\\')]);
    }

    public function isEnum(string $fqcn): bool
    {
        return isset($this->enumSet()[ltrim($fqcn, '\\')]);
    }

    public function isResource(string $fqcn): bool
    {
        return isset($this->resourceSet()[ltrim($fqcn, '\\')]);
    }

    /** @return array<class-string, true> */
    private function modelSet(): array
    {
        return $this->modelSet ??= array_fill_keys($this->models(), true);
    }

    /** @return array<class-string, true> */
    private function enumSet(): array
    {
        return $this->enumSet ??= array_fill_keys($this->enums(), true);
    }

    /** @return array<class-string, true> */
    private function resourceSet(): array
    {
        return $this->resourceSet ??= array_fill_keys($this->resources(), true);
    }

    /**
     * @param  callable(class-string): ?ReflectionClass<object>  $reflect  The class's reflection, or null when it is not of this category (or cannot load).
     * @param  callable(ReflectionClass<object>): bool  $accept
     * @return list<class-string>
     */
    private function discover(string $category, callable $reflect, callable $accept): array
    {
        if (! config(sprintf('typefinder.%s.enabled', $category), true)) {
            return [];
        }

        $classes = [];
        foreach ((array) config(sprintf('typefinder.%s.paths', $category), []) as $path) {
            if (! is_dir((string) $path)) {
                continue;
            }

            foreach (Finder::create()->files()->name('*.php')->in((string) $path)->sortByName() as $file) {
                $className = ClassNameResolver::fromFile($file->getRealPath());
                if ($className === null) {
                    continue;
                }

                $reflection = $reflect($className);
                if (! $reflection instanceof ReflectionClass || ! $accept($reflection)) {
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
