<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Pentacore\Typefinder\Attributes\TypefinderPage;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Throwable;

class ControllerExtractor
{
    /**
     * @return list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>
     */
    public function extract(string $controllerClass): array
    {
        try {
            $reflection = new ReflectionClass($controllerClass);
        } catch (Throwable) {
            return [];
        }

        $results = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->class !== $reflection->getName()) {
                continue;
            }

            foreach ($method->getAttributes(TypefinderPage::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var TypefinderPage $instance */
                $instance = $attr->newInstance();
                $results[] = [
                    'component' => $instance->component,
                    'props' => $instance->props,
                    'optional' => $instance->optional,
                    'source' => $controllerClass.'::'.$method->getName(),
                ];
            }
        }

        return $results;
    }

    /**
     * @return list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null): array
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

            if ($onExtract !== null) {
                $onExtract($className);
            }

            foreach ($this->extract($className) as $entry) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', (string) $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', (string) $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
