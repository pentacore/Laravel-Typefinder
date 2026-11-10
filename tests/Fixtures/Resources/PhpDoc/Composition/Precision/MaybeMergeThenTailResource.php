<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wave 2, case 5: with the flag off maybe() is empty, the merge is an empty
 * list and Laravel renumbers every key after it, so "b" is lost. Not
 * composed.
 */
class MaybeMergeThenTailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->z(), $this->merge($this->maybe($request->boolean('flag'))), ...$this->tail()];
    }

    /**
     * @return array{a: int}
     */
    private function z(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{m?: int}
     */
    private function maybe(bool $on): array
    {
        return $on ? ['m' => 1] : [];
    }

    /**
     * @return array{b: int}
     */
    private function tail(): array
    {
        return ['b' => 1];
    }
}
