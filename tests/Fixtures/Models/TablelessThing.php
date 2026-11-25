<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** A model whose table does not exist (#142). */
final class TablelessThing extends Model
{
    protected $table = 'no_such_table';
}
