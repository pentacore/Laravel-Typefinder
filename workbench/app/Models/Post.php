<?php

namespace App\Models;

use App\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Pentacore\Typefinder\Concerns\HasTypeOverrides;

class Post extends Model
{
    use HasTypeOverrides;

    protected $casts = [
        'status' => PostStatus::class,
        'metadata' => 'array',
        'published_at' => 'datetime',
    ];

    public function typeOverrides(): array
    {
        return [
            'metadata' => 'Record<string, string>',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
