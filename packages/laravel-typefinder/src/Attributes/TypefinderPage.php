<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class TypefinderPage
{
    /**
     * @param  array<string, string>  $props  Prop name → TS type string or class-string.
     * @param  list<string>  $optional  Names in $props emitted as `name?: T`.
     */
    public function __construct(
        public readonly string $component,
        public readonly array $props = [],
        public readonly array $optional = [],
    ) {}
}
