<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A field with no TypeScript equivalent: emitted as unknown, with a warning.
 */
class UnmappableShapeResource extends JsonResource
{
    /**
     * @return array{id: int, handler: callable}
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
