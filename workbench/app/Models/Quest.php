<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    protected $casts = [
        'completed' => 'boolean',
        'rewards' => 'array',
        'completed_at' => 'datetime',
    ];
}
