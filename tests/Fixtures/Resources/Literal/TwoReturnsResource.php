<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Literal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TwoReturnsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($request->has('short')) {
            return ['a' => 'short'];
        }

        return ['a' => 1, 'b' => 'long'];
    }
}
