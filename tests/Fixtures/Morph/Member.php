<?php

declare(strict_types=1);

namespace Tests\Fixtures\Morph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class Member extends Model
{
    protected $table = 'tags';

    public function lastAction(): MorphOne
    {
        return $this->morphOne(Activity::class, 'causer');
    }
}
