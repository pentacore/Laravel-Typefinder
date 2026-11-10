<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Typed through `model:` with omit/extend, and `toArray()` returns exactly
 * the resulting keys: no drift.
 */
#[TypefinderResource(model: Product::class, omit: ['owner_id', 'created_at', 'updated_at'], extend: ['label' => 'string'])]
class ProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => strtoupper($this->name),
        ];
    }
}
