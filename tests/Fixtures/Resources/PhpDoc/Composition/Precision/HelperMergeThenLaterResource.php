<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wave 2, case 1: the undocumented helper's raw merge() is spliced into
 * toArray()'s array, where it keeps "b" against the later spread. Not
 * composed.
 */
class HelperMergeThenLaterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->helper(), ...$this->later()];
    }

    private function helper(): array
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

    /**
     * @return array{b: string}
     */
    private function later(): array
    {
        return ['b' => 'later'];
    }
}
