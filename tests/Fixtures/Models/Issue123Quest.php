<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `completed` has a database default; `title` and `description` have a
 * model default (issue #123).
 */
final class Issue123Quest extends Model
{
    protected $table = 'quests';

    protected $guarded = [];

    protected $attributes = [
        'title' => 'Untitled',
        // Not a default: nothing is filled in.
        'party_id' => null,
    ];

    protected $casts = [
        'completed' => 'boolean',
    ];
}
