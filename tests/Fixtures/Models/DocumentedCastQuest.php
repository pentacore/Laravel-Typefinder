<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Enums\PostStatus;
use App\Http\Resources\UserResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Array casts typed by class-level @property tags (#193).
 *
 * @property list<array{name: string, required: bool}> $rewards
 * @property list<PostStatus>|null $description
 * @property string $title
 * @property UserResource $party_id
 */
final class DocumentedCastQuest extends Model
{
    protected $table = 'quests';

    protected $casts = [
        'rewards' => 'array',
        'description' => 'json',
        'title' => 'string',
        'party_id' => 'array',
    ];
}
