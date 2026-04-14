<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use BackedEnum;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Pentacore\Typefinder\Attributes\TypefinderCast;
use Pentacore\Typefinder\TypefinderRegistry;
use ReflectionAttribute;
use ReflectionClass;

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
    public function __construct(
        protected array $overrides = [],
        protected ?TypefinderRegistry $registry = null,
    ) {}

    /**
     * Resolve a Laravel cast to a TypeScript type. Priority order:
     *
     *   1. Runtime registry (Typefinder::registerCast(...))
     *   2. Config overrides (typefinder.casts.type_map)
     *   3. #[TypefinderCast] attribute on the cast class
     *   4. Built-in name map (e.g. 'datetime')
     *   5. Built-in class map (e.g. AsCollection::class)
     *   6. AsEnumCollection::class:EnumClass
     *   7. BackedEnum detection
     *   8. 'unknown'
     *
     * @return string|array{enum: class-string}|array{enumCollection: class-string}
     */
    public function resolve(string $cast): string|array
    {
        if ($this->registry instanceof TypefinderRegistry) {
            $registered = $this->registry->resolveCast($cast);
            if ($registered !== null) {
                return $registered;
            }
        }

        if (isset($this->overrides[$cast])) {
            return $this->overrides[$cast];
        }

        if (class_exists($cast)) {
            $attrs = (new ReflectionClass($cast))->getAttributes(TypefinderCast::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attrs !== []) {
                return $attrs[0]->newInstance()->type;
            }
        }

        if (isset(self::BUILT_IN_MAP[$cast])) {
            return self::BUILT_IN_MAP[$cast];
        }

        if (isset(self::CLASS_MAP[$cast])) {
            return self::CLASS_MAP[$cast];
        }

        if (str_starts_with($cast, AsEnumCollection::class.':')) {
            $enumClass = substr($cast, strlen(AsEnumCollection::class.':'));

            return ['enumCollection' => $enumClass];
        }

        if (! class_exists($cast) && ! enum_exists($cast)) {
            return 'unknown';
        }

        if (is_subclass_of($cast, BackedEnum::class)) {
            return ['enum' => $cast];
        }

        return 'unknown';
    }
}
