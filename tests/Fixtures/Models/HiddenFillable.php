<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** A fillable column that is hidden stays writable (#192). */
final class HiddenFillable extends Model
{
    protected $table = 'users';

    protected $fillable = ['name', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
