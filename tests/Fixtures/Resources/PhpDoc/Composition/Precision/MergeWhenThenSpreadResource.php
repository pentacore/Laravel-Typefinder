<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 3: when the condition holds, the mergeWhen() "a" wins over the later
 * spread at runtime (the merge is spliced with `+`). Not composed.
 */
class MergeWhenThenSpreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [$this->mergeWhen($request->boolean('flag'), $this->extra()), ...$this->base()];
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
