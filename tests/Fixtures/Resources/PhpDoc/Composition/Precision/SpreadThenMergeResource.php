<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 2: both helpers declare "a"; the merge() keeps the earlier (spread)
 * value at runtime. Not composed.
 */
class SpreadThenMergeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), $this->merge($this->extra())];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{a: string}
     */
    private function extra(): array
    {
        return ['a' => 'x'];
    }
}
