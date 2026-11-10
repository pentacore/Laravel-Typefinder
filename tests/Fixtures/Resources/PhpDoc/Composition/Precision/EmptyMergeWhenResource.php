<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wave 2, case 4: with the flag off the merge is an empty list, and Laravel
 * renumbers every key after it, so "b" is lost. Not composed.
 */
class EmptyMergeWhenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->z(), $this->mergeWhen($request->boolean('flag'), ['x' => 1], []), ...$this->tail()];
    }

    /**
     * @return array{a: int}
     */
    private function z(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{b: int}
     */
    private function tail(): array
    {
        return ['b' => 1];
    }
}
