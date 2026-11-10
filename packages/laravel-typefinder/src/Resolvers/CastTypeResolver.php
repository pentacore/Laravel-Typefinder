<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use BackedEnum;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Casts\AsHtmlString;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\AsUri;
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
        'bool' => 'boolean',
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
        'custom_datetime' => 'string',
        'immutable_custom_datetime' => 'string',
        'array' => 'unknown[]',
        'object' => 'Record<string, unknown>',
        'collection' => 'unknown[]',
        'json' => 'unknown',
        'encrypted' => 'string',
        'encrypted:array' => 'unknown[]',
        'encrypted:collection' => 'unknown[]',
        'encrypted:object' => 'Record<string, unknown>',
        'encrypted:json' => 'unknown',
        'hashed' => 'string',
    ];

    /** @var array<string, string> */
    protected const array CLASS_MAP = [
        AsArrayObject::class => 'Record<string, unknown>',
        AsEncryptedArrayObject::class => 'Record<string, unknown>',
        // Fluent serialises to a JSON object of its attributes, like ArrayObject.
        AsFluent::class => 'Record<string, unknown>',
        AsCollection::class => 'unknown[]',
        AsEncryptedCollection::class => 'unknown[]',
        AsStringable::class => 'string',
        AsUri::class => 'string',
        AsHtmlString::class => 'string',
    ];

    /**
     * Cast-class prefixes whose argument is an enum class; both serialise to
     * a list of that enum's values.
     *
     * @var list<class-string>
     */
    protected const array ENUM_COLLECTION_CASTS = [
        AsEnumCollection::class,
        AsEnumArrayObject::class,
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
     *   6. BackedEnum detection
     *   7. AsEnumCollection / AsEnumArrayObject with an enum argument
     *   8. Parameterised casts ('decimal:2', 'datetime:Y-m-d',
     *      MyCast::class.':arg'): steps 1-6 retried with the part before
     *      the first ':'
     *   9. 'unknown'
     *
     * Exact matches always win, so a key such as 'encrypted:array' or a
     * type_map entry for 'MyCast:arg' beats the mapping of its base.
     *
     * @return string|array{enum: class-string}|array{enumCollection: class-string}
     */
    public function resolve(string $cast): string|array
    {
        return $this->resolveOrNull($cast) ?? 'unknown';
    }

    /**
     * Same as resolve(), but returns null when the cast genuinely has no
     * mapping (as opposed to being explicitly mapped to 'unknown').
     *
     * @return string|array{enum: class-string}|array{enumCollection: class-string}|null
     */
    public function resolveOrNull(string $cast): string|array|null
    {
        $resolved = $this->resolveExact($cast);
        if ($resolved !== null) {
            return $resolved;
        }

        if (! str_contains($cast, ':')) {
            return null;
        }

        [$base, $argument] = explode(':', $cast, 2);

        if (in_array($base, self::ENUM_COLLECTION_CASTS, true)) {
            return ['enumCollection' => $argument];
        }

        return $this->resolveExact($base);
    }

    /**
     * Resolve a cast string as written, without stripping parameters.
     *
     * @return string|array{enum: class-string}|null
     */
    protected function resolveExact(string $cast): string|array|null
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
            $attrs = new ReflectionClass($cast)->getAttributes(TypefinderCast::class, ReflectionAttribute::IS_INSTANCEOF);
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

        if (is_subclass_of($cast, BackedEnum::class)) {
            return ['enum' => $cast];
        }

        return null;
    }
}
