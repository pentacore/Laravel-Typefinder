<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Pentacore\Typefinder\TypefinderRegistry;

/**
 * @method static void registerCast(string $castClass, string|Closure $type)
 * @method static ?string resolveCast(string $castClass)
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
