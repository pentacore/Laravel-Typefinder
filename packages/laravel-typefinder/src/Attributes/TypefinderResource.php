<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderResource
{
    /**
     * @param  array<string, string>  $shape  Explicit TS shape. Mutually exclusive with $model.
     * @param  ?string  $model  Model FQCN to wrap (Tier 2).
     * @param  list<string>  $omit  Model field names to exclude.
     * @param  array<string, string>  $extend  Extra fields appended to the model.
     */
    public function __construct(
        public array $shape = [],
        public ?string $model = null,
        public array $omit = [],
        public array $extend = [],
    ) {}
}
