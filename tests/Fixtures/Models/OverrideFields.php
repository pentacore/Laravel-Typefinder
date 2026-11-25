<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/** Override keys that add fields or replace a relation (#144). */
#[TypefinderOverrides([
    'name' => "'a' | 'b'",
    'full_title' => 'string',
    'children' => 'string[]',
    'children_count' => 'number',
    'secret_note' => 'string',
])]
final class OverrideFields extends Model
{
    protected $table = 'tags';

    protected $hidden = ['secret_note'];

    public function children(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
