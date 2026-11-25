<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\HasDatabaseNotifications;

/** Relations without a return type, that throw, or that are hidden (#143, #149). */
class RelationEdgeCases extends Model
{
    // Untyped relation methods in vendor code are not probed.
    use HasDatabaseNotifications;

    protected $table = 'tags';

    protected $hidden = ['secretOwner'];

    /** @phpstan-ignore missingType.return */
    public function untyped()
    {
        return $this->hasMany(Tag::class);
    }

    public function broken(): HasMany
    {
        return $this->hasMany((string) config('typefinder.no_such_model_class'));
    }

    public function secretOwner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** An intersection type is never a relation. */
    public function both(): \Countable&\IteratorAggregate
    {
        throw new \LogicException('must not be called');
    }

    /** Untyped, but not a relation: never called. */
    public function shout(): never
    {
        throw new \LogicException('must not be called');
    }
}
