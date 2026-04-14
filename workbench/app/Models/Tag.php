<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }
}
