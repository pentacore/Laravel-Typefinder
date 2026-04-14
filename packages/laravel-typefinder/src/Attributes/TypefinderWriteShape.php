<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderWriteShape
{
    /**
     * @param  list<string>  $serverFilled  Extra server-filled columns.
     * @param  ?bool  $respectMassAssignment  null = inherit global config.
     * @param  list<string>  $immutableOnUpdate  Extra columns excluded from Update shape.
     */
    public function __construct(
        public array $serverFilled = [],
        public ?bool $respectMassAssignment = null,
        public array $immutableOnUpdate = [],
    ) {}
}
