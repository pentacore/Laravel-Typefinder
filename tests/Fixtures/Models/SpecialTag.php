<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Tag;

/** Inherits `posts()` from Tag (issue #93). */
final class SpecialTag extends Tag
{
    protected $table = 'tags';
}
