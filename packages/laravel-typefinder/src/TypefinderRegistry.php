<?php

declare(strict_types=1);

namespace Pentacore\Typefinder;

use Closure;
use Pentacore\Typefinder\Facades\Typefinder;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;

/**
 * Runtime registry for custom cast types that can't be declared at the class
 * source (typically third-party package casts the application depends on).
 *
 * Bound as a singleton in {@see TypefinderServiceProvider::register()} and
 * exposed through the {@see Typefinder} facade.
 *
 * Priority within {@see CastTypeResolver}:
 *   1. Runtime registry (this class)
 *   2. Config overrides (`typefinder.casts.type_map`)
 *   3. `#[TypefinderCast]` attribute on the cast class
 *   4. Built-in name / class map
 *   5. `BackedEnum` detection
 *   6. `'unknown'` fallback
 *
 * Typical usage — register casts from a service provider's `boot()`:
 * ```php
 * use Pentacore\Typefinder\Facades\Typefinder;
 *
 * Typefinder::registerCast(
 *     \Spatie\MediaLibrary\Cast::class,
 *     'Media[]',
 * );
 *
 * Typefinder::registerCast(
 *     \Cknow\Money\MoneyCast::class,
 *     fn (): string => config('app.strict_money')
 *         ? '{ amount: number; currency: string; formatted: string }'
 *         : '{ amount: number; currency: string }',
 * );
 * ```
 */
class TypefinderRegistry
{
    /** @var array<class-string, string|Closure(): string> */
    protected array $casts = [];

    /**
     * Register a cast class → TypeScript type mapping.
     *
     * The `$type` argument may be:
     *   - a literal TS type string (e.g. `'Media[]'`), or
     *   - a `Closure` returning a string — invoked once per generation run,
     *     useful when the emitted shape depends on runtime config.
     *
     * Later registrations for the same class overwrite earlier ones; there's
     * no deduplication or merging.
     *
     * @param  class-string  $castClass  FQCN of a cast class (implements
     *                                   `Illuminate\Contracts\Database\Eloquent\CastsAttributes`).
     * @param  string|Closure(): string  $type  TS type, or a closure returning one.
     */
    public function registerCast(string $castClass, string|Closure $type): void
    {
        $this->casts[$castClass] = $type;
    }

    /**
     * Resolve a cast class FQCN to its registered TS type.
     *
     * @param  class-string  $castClass
     * @return ?string The TS type string, or `null` if no registration exists.
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
