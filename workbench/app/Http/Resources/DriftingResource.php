<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Key drift: `toArray()` returns `name`, which the shape does not declare,
 * and never returns the declared `title`.
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'title' => 'string',
])]
class DriftingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
