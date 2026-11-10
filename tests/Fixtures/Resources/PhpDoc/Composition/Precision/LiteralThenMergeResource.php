<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 1: Laravel splices a merge() with `+`, so the literal "a" wins at
 * runtime over the helper's documented int. Not composed.
 */
class LiteralThenMergeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['a' => 'literal', $this->merge($this->helper())];
    }

    /**
     * @return array{a: int}
     */
    private function helper(): array
    {
        return ['a' => 1];
    }
}
