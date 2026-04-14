<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;

#[TypefinderWriteShape(
    serverFilled: ['reference'],
    respectMassAssignment: false,
    immutableOnUpdate: ['customer_id'],
)]
class Invoice extends Model {}
