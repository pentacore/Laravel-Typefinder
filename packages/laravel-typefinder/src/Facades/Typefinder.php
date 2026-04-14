<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Pentacore\Typefinder\Attributes\TypefinderCast;
use Pentacore\Typefinder\TypefinderRegistry;

/**
 * Public facade for the Typefinder runtime registry.
 *
 * Primary use case: declaring TypeScript types for third-party cast classes
 * you can't modify (Spatie packages, Cknow/Money, etc.). Call from a service
 * provider's `boot()` method so the registration is in place before the
 * generator runs.
 *
 * Example:
 * ```php
 * // AppServiceProvider::boot()
 * use Pentacore\Typefinder\Facades\Typefinder;
 *
 * Typefinder::registerCast(\Spatie\MediaLibrary\Cast::class, 'Media[]');
 *
 * Typefinder::registerCast(
 *     \Cknow\Money\MoneyCast::class,
 *     fn (): string => '{ amount: number; currency: string }',
 * );
 * ```
 *
 * For casts you own, prefer the
 * {@see TypefinderCast} attribute on the cast
 * class itself — same effect, declaration lives next to the cast code.
 *
 * @method static void registerCast(string $castClass, string|Closure $type) Register a cast class → TS type mapping.
 * @method static ?string resolveCast(string $castClass) Resolve a cast class to its registered TS type, or null.
 *
 * @see TypefinderRegistry
 */
class Typefinder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TypefinderRegistry::class;
    }
}
