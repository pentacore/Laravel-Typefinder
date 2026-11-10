<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 8: an open helper first in the return composes, open.
 */
class OpenFirstResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->meta(), 'x' => 1];
    }

    /**
     * @return array{v: int, ...}
     */
    private function meta(): array
    {
        return ['v' => 1, 'extra' => true];
    }
}
