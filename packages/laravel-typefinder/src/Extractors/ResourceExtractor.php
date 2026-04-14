<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderResource;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

class ResourceExtractor
{
    /**
     * @return ?array{name: string, fqcn: class-string, shape: array}
     */
    public function extract(string $resourceClass): ?array
    {
        $reflection = new ReflectionClass($resourceClass);

        if (! $reflection->isSubclassOf(JsonResource::class)) {
            return null;
        }

        if ($reflection->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return null;
        }

        $attribute = $this->getAttribute($reflection);

        if ($attribute !== null && $attribute->shape !== [] && $attribute->model !== null) {
            throw new \RuntimeException(
                "TypefinderResource on {$resourceClass}: `shape` and `model` are mutually exclusive.",
            );
        }

        if ($attribute !== null && $attribute->shape !== []) {
            return [
                'name' => $reflection->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => ['kind' => 'shape', 'fields' => $attribute->shape],
            ];
        }

        if ($attribute !== null && $attribute->model !== null) {
            if (! class_exists($attribute->model) || ! is_subclass_of($attribute->model, Model::class)) {
                return null;
            }

            return [
                'name' => $reflection->getShortName(),
                'fqcn' => $resourceClass,
                'shape' => [
                    'kind' => 'model',
                    'model' => $attribute->model,
                    'omit' => $attribute->omit,
                    'extend' => $attribute->extend,
                ],
            ];
        }

        $short = $reflection->getShortName();
        if (str_ends_with($short, 'Resource')) {
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
            $className = $this->resolveClassName($file->getRealPath());
            if ($className === null || ! class_exists($className)) {
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
                        $onWarn($className, new \RuntimeException(
                            'No #[TypefinderResource] attribute and class name does not match any discovered model. Add a shape or model attribute to include this resource.',
                        ));
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

    protected function getAttribute(ReflectionClass $reflection): ?TypefinderResource
    {
        $attrs = $reflection->getAttributes(TypefinderResource::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Best-effort lookup of a Model FQCN by short name. Walks declared classes
     * for a Model subclass whose short name matches. Returns null if no match.
     */
    protected function resolveModelFqcn(string $shortName): ?string
    {
        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            if ((new ReflectionClass($class))->getShortName() === $shortName) {
                return $class;
            }
        }

        return null;
    }

    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', (string) $contents, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', (string) $contents, $cls)) {
            return null;
        }

        return $ns[1].'\\'.$cls[1];
    }
}
