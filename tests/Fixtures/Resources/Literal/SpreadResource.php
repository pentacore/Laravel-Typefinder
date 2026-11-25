<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Literal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Spreads the model's attributes, so its keys are not all known. */
class SpreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'extra' => 1];
    }
}
