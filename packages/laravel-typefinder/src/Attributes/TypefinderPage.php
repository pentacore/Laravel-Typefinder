<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class TypefinderPage
{
    /**
     * @param  array<string, string>  $props  Prop name → TS type string or class-string.
     * @param  list<string>  $optional  Names in $props emitted as `name?: T`.
     */
    public function __construct(
        public string $component,
        public array $props = [],
        public array $optional = [],
    ) {}
}
