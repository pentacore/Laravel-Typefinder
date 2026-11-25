<?php

declare(strict_types=1);

namespace Tests\Fixtures\Pivots;

use App\Enums\PostStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class LabelThing extends Pivot
{
    protected $table = 'label_thing';

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => PostStatus::class,
    ];
}
