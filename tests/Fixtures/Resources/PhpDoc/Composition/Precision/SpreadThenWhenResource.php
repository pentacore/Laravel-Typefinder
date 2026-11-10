<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 4: without the flag the later when() removes "a" altogether; the
 * helper's value does not come back. Not composed.
 */
class SpreadThenWhenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), 'a' => $this->when($request->boolean('flag'), 'str')];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }
}
