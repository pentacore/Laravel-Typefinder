<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderResource;

// Marked ignore so it doesn't break directory-wide scans; tests that need the
// validation error invoke extract() directly.
#[TypefinderIgnore]
#[TypefinderResource(
    shape: ['id' => 'number'],
    model: User::class,
)]
class InvalidResource extends JsonResource {}
