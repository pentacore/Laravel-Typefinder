<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Support\ClassDiscovery;
use ReflectionEnum;

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
        $results = [];

        foreach (ClassDiscovery::in($path, $onWarn) as $className) {
            if (! $this->isEmittable($className)) {
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
     * Whether a class gets an enum type of its own: an enum, backed (a pure
     * enum has no values to emit) and not `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $enumClass
     */
    public function isEmittable(string $enumClass): bool
    {
        if (! enum_exists($enumClass)) {
            return false;
        }

        $reflectionEnum = new ReflectionEnum($enumClass);

        return $reflectionEnum->isBacked()
            && $reflectionEnum->getAttributes(TypefinderIgnore::class, \ReflectionAttribute::IS_INSTANCEOF) === [];
    }
}
