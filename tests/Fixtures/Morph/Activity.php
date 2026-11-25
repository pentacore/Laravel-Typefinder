<?php

declare(strict_types=1);

namespace Tests\Fixtures\Morph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Two morphTo relations, each inverted by a different model (#139). */
final class Activity extends Model
{
    protected $table = 'tags';

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
}
