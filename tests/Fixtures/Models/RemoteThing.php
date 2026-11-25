<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** A model on a non-default connection (#141). */
final class RemoteThing extends Model
{
    protected $connection = 'other';

    protected $table = 'remote_things';
}
