<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** `$visible` filters appended attributes the same way as columns (issue #94). */
final class VisibleBadge extends Model
{
    protected $table = 'tags';

    protected $visible = ['id', 'shout'];

    protected $appends = ['shout', 'whisper'];

    public function getShoutAttribute(): string
    {
        return strtoupper((string) $this->name);
    }

    public function getWhisperAttribute(): string
    {
        return strtolower((string) $this->name);
    }
}
