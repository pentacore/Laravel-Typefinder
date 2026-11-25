<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A morphTo that no model inverts with morphMany / morphOne (#133).
 */
class Attachment extends Model
{
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
