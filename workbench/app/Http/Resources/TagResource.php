<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Not composable: `parent::toArray()` is JsonResource's own (the model's
 * attributes), so the name convention types it as `Tag`, as before.
 */
class TagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), ['x' => 1]);
    }
}
