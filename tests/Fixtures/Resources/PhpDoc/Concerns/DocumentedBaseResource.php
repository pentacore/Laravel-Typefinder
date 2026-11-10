<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentedBaseResource extends JsonResource
{
    /**
     * @return array{base_only: int}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
