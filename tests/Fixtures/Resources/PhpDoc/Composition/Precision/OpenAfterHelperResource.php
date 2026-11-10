<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 8: the open meta() may override "a" at runtime (it does here). Not
 * composed.
 */
class OpenAfterHelperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), ...$this->meta()];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }

    /**
     * @return array{v: int, ...}
     */
    private function meta(): array
    {
        return ['v' => 1, 'a' => 'override'];
    }
}
