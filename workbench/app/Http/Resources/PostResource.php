<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: [
    'id' => 'number',
    'title' => 'string',
    'author' => UserResource::class,
    'published_at' => 'string | null',
])]
class PostResource extends JsonResource {}
