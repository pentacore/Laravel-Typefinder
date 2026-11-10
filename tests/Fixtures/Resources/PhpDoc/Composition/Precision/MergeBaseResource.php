<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Undocumented parent toArray() with a merge(), for
 * {@see ParentMergeThenLaterResource}.
 */
abstract class MergeBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->z(), $this->merge($this->x())];
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
    private function x(): array
    {
        return ['b' => 1];
    }
}
