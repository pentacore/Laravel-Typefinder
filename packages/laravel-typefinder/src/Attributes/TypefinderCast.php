<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Declare the TypeScript type produced by a custom cast class.
 *
 * Use on classes implementing Laravel's `CastsAttributes` to tell Typefinder
 * what shape the cast's return value has:
 *
 *     #[TypefinderCast('{ theme: string; notifications: boolean }')]
 *     class SettingsCast implements CastsAttributes {}
 *
 * For third-party casts you don't control, use the Typefinder facade's
 * `registerCast()` method from a service provider instead.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderCast
{
    public function __construct(public string $type) {}
}
