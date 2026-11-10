<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 9: a disjoint mergeUnless() composes; its keys are optional.
 */
class DisjointMergeUnlessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), $this->mergeUnless($request->boolean('flag'), $this->extra())];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{b: string}
     */
    private function extra(): array
    {
        return ['b' => 'x'];
    }
}
