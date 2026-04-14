<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Concerns\HasWriteShapeContract;

class Invoice extends Model
{
    use HasWriteShapeContract;

    public static function typefinderServerFilled(): array
    {
        return ['reference'];
    }

    public static function typefinderRespectMassAssignment(): ?bool
    {
        return false;
    }

    public static function typefinderImmutableOnUpdate(): array
    {
        return ['customer_id'];
    }
}
