<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wave 2, case 3: both merge() values sit at key 0, so `+` drops the right
 * one and "c" is never returned. Not composed.
 */
class PlusMergeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->z(), $this->merge($this->x())] + [$this->merge($this->y())];
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

    /**
     * @return array{c: int}
     */
    private function y(): array
    {
        return ['c' => 1];
    }
}
