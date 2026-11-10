<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class PrivateBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base()];
    }

    /**
     * Private: `$this->base()` in this class always calls this one.
     *
     * @return array{id: int}
     */
    private function base(): array
    {
        return ['id' => 1];
    }
}
