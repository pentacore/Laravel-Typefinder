<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use ReflectionEnum;
use Symfony\Component\Finder\Finder;

class EnumExtractor
{
    /**
     * Extract type information from a single backed enum class.
     *
     * @param  class-string<BackedEnum>  $enumClass
     * @return array{name: string, fqcn: class-string, backingType: string, values: list<string|int>}
     */
    public function extract(string $enumClass): array
    {
        $reflectionEnum = new ReflectionEnum($enumClass);
        $backingType = $reflectionEnum->getBackingType();

        $values = array_map(
            fn (BackedEnum $backedEnum): int|string => $backedEnum->value,
            $enumClass::cases()
        );

        return [
            'name' => $reflectionEnum->getShortName(),
            'fqcn' => $enumClass,
            'backingType' => (string) $backingType,
            'values' => $values,
        ];
    }

    /**
     * Discover and extract all backed enums from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, backingType: string, values: list<string|int>}>
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
            if ($className === null) {
                continue;
            }

            if (! enum_exists($className)) {
                continue;
            }

            $reflection = new ReflectionEnum($className);

            if (! $reflection->isBacked()) {
                continue;
            }

            if ($reflection->getAttributes(TypefinderIgnore::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $results[] = $this->extract($className);
        }

        return $results;
    }

    /**
     * Resolve the fully qualified class name from a PHP file.
     */
    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/enum\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
