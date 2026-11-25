<?php

declare(strict_types=1);

namespace Tests\Fixtures\SnakeRelations;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/** Multi-word relations, which `toArray()` snake-cases (#220). */
#[TypefinderOverrides([
    'pinnedTag' => 'string',
    'featured_tag' => 'number',
])]
class Author extends Model
{
    protected $table = 'tags';

    protected $hidden = ['secretPosts'];

    public function blogPosts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function latestPost(): HasOne
    {
        return $this->hasOne(Post::class)->latestOfMany();
    }

    public function secretPosts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function taggedPosts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable', 'taggables', 'tag_id');
    }

    public function pinnedTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function featuredTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
