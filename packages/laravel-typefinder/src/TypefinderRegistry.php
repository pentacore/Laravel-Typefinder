<?php

declare(strict_types=1);

namespace Pentacore\Typefinder;

use Closure;

/**
 * Runtime registry for third-party cast types. Service providers can call
 * `Typefinder::registerCast()` (via the facade) to declare TypeScript types
 * for cast classes they don't own.
 */
class TypefinderRegistry
{
    /** @var array<class-string, string|Closure> */
    protected array $casts = [];

    /**
     * Register a cast class → TS type mapping.
     *
     * The type argument may be a literal TS string or a Closure returning one.
     * Closures are invoked at generation time, useful when the shape depends
     * on runtime config.
     */
    public function registerCast(string $castClass, string|Closure $type): void
    {
        $this->casts[$castClass] = $type;
    }

    /**
     * Resolve a cast class FQCN to its registered TS type, or null if none.
     */
    public function resolveCast(string $castClass): ?string
    {
        if (! isset($this->casts[$castClass])) {
            return null;
        }

        $value = $this->casts[$castClass];

        return $value instanceof Closure ? (string) $value() : $value;
    }
}
