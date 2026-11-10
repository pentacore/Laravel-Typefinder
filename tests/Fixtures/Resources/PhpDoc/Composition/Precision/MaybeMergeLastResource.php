<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wave 2, case 6: a merge that may be empty but is the last part loses
 * nothing. Composed, "m" optional.
 */
class MaybeMergeLastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->z(), $this->merge($this->maybe($request->boolean('flag')))];
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
}
