<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SelfRecursiveHelperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->part()];
    }

    private function part(): array
    {
        return [...$this->part(), 'id' => 1];
    }
}
