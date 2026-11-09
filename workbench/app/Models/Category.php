<?php

namespace App\Models;

use App\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * Self-referencing fixture: both relations point back at Category, so the
 * generated Category.d.ts must not import its own name. The overrides use
 * class-strings, which must resolve to imports relative to models/ (#75).
 */
#[TypefinderOverrides([
    'featured_status' => PostStatus::class,
    'owner_snapshot' => User::class.' | null',
    'root_snapshot' => Category::class,
])]
class Category extends Model
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
