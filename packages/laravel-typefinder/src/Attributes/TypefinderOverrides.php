<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderOverrides
{
    /**
     * @param  array<string, string>  $overrides  column name → TS type string
     */
    public function __construct(public array $overrides) {}
}
