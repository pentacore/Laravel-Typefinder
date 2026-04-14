<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;
use Pentacore\Typefinder\Facades\Typefinder;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;

/**
 * Declare the TypeScript type produced by a custom Laravel cast class.
 *
 * Place on a class implementing `Illuminate\Contracts\Database\Eloquent\CastsAttributes`
 * to tell Typefinder what shape the cast's `get()` method returns. The declared
 * type becomes the TS type for any model column cast to this class.
 *
 * Example:
 * ```php
 * use Pentacore\Typefinder\Attributes\TypefinderCast;
 * use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
 *
 * #[TypefinderCast('{ theme: string; notifications: boolean }')]
 * class SettingsCast implements CastsAttributes
 * {
 *     public function get($model, $key, $value, $attributes): mixed { … }
 *     public function set($model, $key, $value, $attributes): mixed { … }
 * }
 * ```
 *
 * For cast classes you don't own (e.g. casts shipped by third-party packages),
 * use the runtime registry instead — see
 * {@see Typefinder::registerCast()}.
 *
 * Priority in {@see CastTypeResolver}: runtime
 * registry > config overrides > this attribute > built-in name map >
 * `BackedEnum` detection > `'unknown'`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderCast
{
    /**
     * @param  string  $type  The TypeScript type emitted for columns cast to this class.
     */
    public function __construct(public string $type) {}
}
