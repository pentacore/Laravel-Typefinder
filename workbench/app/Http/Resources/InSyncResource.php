<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * No key drift: `toArray()` returns exactly the declared keys. The nested
 * closure's own `return [...]` is not a return of `toArray()`.
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'name' => 'string',
    'labels' => '{ text: string }[]',
])]
class InSyncResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'labels' => collect($this->labels)->map(function (string $label): array {
                return ['text' => $label];
            })->all(),
        ];
    }
}
