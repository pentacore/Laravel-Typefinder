<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Key drift on an open return: `parent::toArray()` may hold any key, so only
 * the literal `extra` (not declared) is reported, never a missing key.
 */
#[TypefinderResource(shape: [
    'id' => 'number',
])]
class OpenReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'extra' => true,
        ]);
    }
}
