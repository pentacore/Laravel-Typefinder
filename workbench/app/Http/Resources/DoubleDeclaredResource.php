<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * The attribute shape wins, but the PHPDoc shape on `toArray()` disagrees
 * with it. `toArray()` itself agrees with the attribute.
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'title' => 'string',
    'status' => 'string',
])]
class DoubleDeclaredResource extends JsonResource
{
    /**
     * @return array{id: int, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
        ];
    }
}
