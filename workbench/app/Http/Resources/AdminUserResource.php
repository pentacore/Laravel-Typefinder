<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(
    model: User::class,
    omit: ['password'],
    extend: ['roles' => 'string[]'],
)]
class AdminUserResource extends JsonResource {}
