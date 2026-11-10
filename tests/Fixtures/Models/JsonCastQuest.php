<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Quest;

final class JsonCastQuest extends Quest
{
    protected $table = 'quests';

    protected $casts = [
        'rewards' => 'json',
    ];
}
