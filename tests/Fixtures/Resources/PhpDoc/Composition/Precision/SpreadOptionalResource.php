<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 5: a later helper declares "a" optional over an earlier one. Not
 * composed.
 */
class SpreadOptionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), ...$this->extra($request)];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{a?: string}
     */
    private function extra(Request $request): array
    {
        return $request->boolean('flag') ? ['a' => 's'] : [];
    }
}
