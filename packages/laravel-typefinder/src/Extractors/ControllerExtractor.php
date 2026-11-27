<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderPage;
use Pentacore\Typefinder\Resolvers\PagePropType;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\GeneratedClasses;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class ControllerExtractor
{
    private readonly PagePropType $pagePropType;

    /**
     * @param  ?GeneratedClasses  $classes  the models, enums and resources a PHPDoc prop type may name (discovered from config when null)
     * @param  null|callable(string): void  $onWarn  told about prop types that are emitted as `unknown`
     */
    public function __construct(?GeneratedClasses $classes = null, ?callable $onWarn = null)
    {
        $this->pagePropType = new PagePropType($classes ?? new GeneratedClasses, $onWarn);
    }

    /**
     * @return list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>
     */
    public function extract(string $controllerClass): array
    {
        try {
            $reflectionClass = new ReflectionClass($controllerClass);
        } catch (Throwable) {
            return [];
        }

        $results = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->isStatic()) {
                continue;
            }

            if ($reflectionMethod->class !== $reflectionClass->getName()) {
                continue;
            }

            foreach ($reflectionMethod->getAttributes(TypefinderPage::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var TypefinderPage $instance */
                $instance = $attr->newInstance();
                $results[] = [
                    'component' => $instance->component,
                    'props' => $this->propTypes($instance->props, $reflectionClass->getName(), $reflectionMethod->getName()),
                    'optional' => $instance->optional,
                    'source' => $controllerClass.'::'.$reflectionMethod->getName(),
                ];
            }
        }

        return $results;
    }

    /**
     * Each prop's TypeScript type; a PHPDoc type is converted (#247).
     *
     * @param  array<array-key, string>  $props
     * @param  class-string  $controller
     * @return array<string, string>
     */
    private function propTypes(array $props, string $controller, string $method): array
    {
        $types = [];
        foreach ($props as $name => $type) {
            $types[(string) $name] = $this->pagePropType->resolve((string) $type, $controller, $method, (string) $name);
        }

        return $types;
    }

    /**
     * Whether a controller's `#[TypefinderPage]` actions are read: it is not
     * `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $controllerClass
     */
    public function isEmittable(string $controllerClass): bool
    {
        return new ReflectionClass($controllerClass)->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }

    /**
     * @return list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>
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

            foreach ($this->extract($className) as $entry) {
                $results[] = $entry;
            }
        }

        return $results;
    }
}
