<?php

declare(strict_types=1);

namespace Tests\Fixtures\SnakeRelations;

use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/** A model that keeps relation keys as their method names (#220). */
#[TypefinderOverrides([
    'pinnedTag' => 'string',
    'featured_tag' => 'number',
])]
class CamelAuthor extends Author
{
    public static $snakeAttributes = false;
}
