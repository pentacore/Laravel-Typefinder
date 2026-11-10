<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HelperCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->first()];
    }

    private function first(): array
    {
        return [...$this->second(), 'a' => 1];
    }

    private function second(): array
    {
        return [...$this->first(), 'b' => 2];
    }
}
