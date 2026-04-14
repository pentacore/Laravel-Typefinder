<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use BackedEnum;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Pentacore\Typefinder\Contracts\HasTypeDefinition;

class CastTypeResolver
{
    /** @var array<string, string> */
    protected const array BUILT_IN_MAP = [
        'string' => 'string',
        'boolean' => 'boolean',
        'integer' => 'number',
        'int' => 'number',
        'float' => 'number',
        'real' => 'number',
        'double' => 'number',
        'decimal' => 'number',
        'datetime' => 'string',
        'date' => 'string',
        'timestamp' => 'string',
        'immutable_datetime' => 'string',
        'immutable_date' => 'string',
        'array' => 'unknown[]',
        'object' => 'Record<string, unknown>',
        'collection' => 'unknown[]',
        'json' => 'unknown',
        'encrypted' => 'string',
        'encrypted:array' => 'unknown[]',
        'encrypted:collection' => 'unknown[]',
        'encrypted:object' => 'Record<string, unknown>',
        'hashed' => 'string',
    ];

    /** @var array<string, string> */
    protected const array CLASS_MAP = [
        AsArrayObject::class => 'Record<string, unknown>',
        AsCollection::class => 'unknown[]',
        AsStringable::class => 'string',
    ];

    /**
     * @param  array<string, string>  $overrides
     */
    public function __construct(protected array $overrides = []) {}

    /**
     * Resolve a Laravel cast to a TypeScript type.
     *
     * Returns a string for simple types, or an array with 'enum' or 'enumCollection'
     * key for types that reference enum classes.
     *
     * @return string|array{enum: class-string}|array{enumCollection: class-string}
     */
    public function resolve(string $cast): string|array
    {
        // Check config overrides first
        if (isset($this->overrides[$cast])) {
            return $this->overrides[$cast];
        }

        // Check built-in string casts
        if (isset(self::BUILT_IN_MAP[$cast])) {
            return self::BUILT_IN_MAP[$cast];
        }

        // Check Laravel class casts
        if (isset(self::CLASS_MAP[$cast])) {
            return self::CLASS_MAP[$cast];
        }

        // Handle AsEnumCollection::class.':'.EnumClass
        if (str_starts_with($cast, AsEnumCollection::class.':')) {
            $enumClass = substr($cast, strlen(AsEnumCollection::class.':'));

            return ['enumCollection' => $enumClass];
        }

        // Check if it's a class
        if (! class_exists($cast) && ! enum_exists($cast)) {
            return 'unknown';
        }

        // Check if it's a backed enum
        if (is_subclass_of($cast, BackedEnum::class)) {
            return ['enum' => $cast];
        }

        // Check if it implements HasTypeDefinition
        if (is_subclass_of($cast, HasTypeDefinition::class)) {
            return $cast::typeDefinition();
        }

        return 'unknown';
    }
}
