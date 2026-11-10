<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Quest;
use Tests\Fixtures\Casts\UnmappedCast;

final class UnmappedCastQuest extends Quest
{
    protected $table = 'quests';

    protected $casts = [
        'completed' => 'boolean',
        'rewards' => UnmappedCast::class,
        'completed_at' => 'datetime',
    ];
}
