<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Support\ClassNameResolver;
use ReflectionEnum;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class EnumExtractor
{
    /**
     * Extract type information from a single backed enum class.
     *
     * @param  class-string<BackedEnum>  $enumClass
     * @return array{name: string, fqcn: class-string, backingType: string, values: list<string|int>, cases: list<array{name: string, value: string|int}>}
     */
    public function extract(string $enumClass): array
    {
        $reflectionEnum = new ReflectionEnum($enumClass);
        $backingType = $reflectionEnum->getBackingType();

        $cases = array_map(
            fn (BackedEnum $backedEnum): array => ['name' => $backedEnum->name, 'value' => $backedEnum->value],
            $enumClass::cases()
        );

        return [
            'name' => $reflectionEnum->getShortName(),
            'fqcn' => $enumClass,
            'backingType' => (string) $backingType,
            'values' => array_column($cases, 'value'),
            'cases' => $cases,
        ];
    }

    /**
     * Discover and extract all backed enums from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, backingType: string, values: list<string|int>}>
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

            if (! enum_exists($className)) {
                $failure = ClassNameResolver::autoloadFailure($className, $file->getRealPath());
                if ($failure instanceof RuntimeException && $onWarn !== null) {
                    $onWarn($className, $failure);
                }

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
}
