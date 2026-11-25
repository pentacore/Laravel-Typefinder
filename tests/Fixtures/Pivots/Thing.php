<?php

declare(strict_types=1);

namespace Tests\Fixtures\Pivots;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A pivot with a typed table, a `using` class and a custom accessor (#145). */
final class Thing extends Model
{
    protected $table = 'things';

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'label_thing', 'thing_id', 'label_id')
            ->as('tagging')
            ->using(LabelThing::class)
            ->withPivot('quantity', 'is_primary', 'status', 'note');
    }
}
