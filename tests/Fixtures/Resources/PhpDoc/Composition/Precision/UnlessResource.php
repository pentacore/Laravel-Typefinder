<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 7: unless() without a default is conditional.
 */
class UnlessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), 'secret' => $this->unless($request->boolean('flag'), 's')];
    }

    /**
     * @return array{a: int}
     */
    private function base(): array
    {
        return ['a' => 1];
    }
}
