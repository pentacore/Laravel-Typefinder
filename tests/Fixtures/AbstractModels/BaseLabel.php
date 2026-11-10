<?php

declare(strict_types=1);

namespace Tests\Fixtures\AbstractModels;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/** An abstract base model: never emitted itself, its relations are inherited. */
abstract class BaseLabel extends Model
{
    protected $table = 'tags';

    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable', 'taggables', 'tag_id');
    }
}
