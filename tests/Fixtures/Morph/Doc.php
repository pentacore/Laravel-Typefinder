<?php

declare(strict_types=1);

namespace Tests\Fixtures\Morph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class Doc extends Model
{
    protected $table = 'tags';

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
