<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * toArray() → one() → two() → three(): three delegations, the limit.
 */
class ChainAtLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->one();
    }

    private function one(): array
    {
        return $this->two();
    }

    private function two(): array
    {
        return $this->three();
    }

    /**
     * @return array{deep: int}
     */
    private function three(): array
    {
        return ['deep' => 3];
    }
}
