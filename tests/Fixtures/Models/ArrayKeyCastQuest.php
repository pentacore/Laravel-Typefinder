<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An `array-key` keyed @property is a list or a record (#234).
 *
 * @property array<array-key, mixed>|null $description
 * @property array<string, int> $rewards
 */
final class ArrayKeyCastQuest extends Model
{
    protected $table = 'quests';

    protected $casts = [
        'description' => 'array',
        'rewards' => 'array',
    ];
}
