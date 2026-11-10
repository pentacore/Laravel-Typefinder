<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Quest;
use Tests\Fixtures\Casts\MoneyCast;

final class ParameterisedCastQuest extends Quest
{
    protected $table = 'quests';

    protected $casts = [
        'party_id' => 'decimal:2',
        'completed_at' => 'datetime:Y-m-d',
        'rewards' => MoneyCast::class.':EUR',
    ];
}
